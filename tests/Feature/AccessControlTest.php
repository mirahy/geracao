<?php

namespace Tests\Feature;

use App\Filament\Resources\ImportedDocuments\ImportedDocumentResource;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\SolarGenerations\Pages\ManageSolarGenerations;
use App\Filament\Resources\SolarGenerations\SolarGenerationResource;
use App\Filament\Resources\SolarInstallments\SolarInstallmentResource;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ConsumerUnit;
use App\Models\ImportedDocument;
use App\Models\Invoice;
use App\Models\SolarGeneration;
use App\Models\UnitAccess;
use App\Models\User;
use App\Services\Dashboard\EnergyDashboard;
use App\Services\Documents\DocumentImporter;
use App\Support\AccessControl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccessControlTest extends TestCase
{
    use RefreshDatabase;

    private ConsumerUnit $generator;

    private ConsumerUnit $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $importer = app(DocumentImporter::class);
        foreach (['demonstrativo_2026_11.txt', 'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt'] as $file) {
            $importer->importText(file_get_contents(__DIR__.'/../Fixtures/'.$file));
        }

        $this->generator = ConsumerUnit::where('role', 'generator')->firstOrFail();
        $this->receiver = ConsumerUnit::where('role', 'receiver')->whereHas('invoices')->firstOrFail();
    }

    /** @param array<int, bool> $units unidade => pode editar */
    private function userWith(string $role, array $units = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        foreach ($units as $unitId => $canEdit) {
            UnitAccess::create(['user_id' => $user->id, 'consumer_unit_id' => $unitId, 'can_edit' => $canEdit]);
        }

        return $user;
    }

    public function test_user_only_sees_data_of_allowed_units(): void
    {
        $this->actingAs($this->userWith('Consulta', [$this->receiver->id => false]));

        $this->assertSame([$this->receiver->id], ConsumerUnit::pluck('id')->all());
        $this->assertSame([$this->receiver->id], Invoice::pluck('consumer_unit_id')->unique()->values()->all());
        $this->assertSame([$this->receiver->id], EnergyDashboard::make()->units()->pluck('id')->all());

        Livewire::test(ListInvoices::class)
            ->assertCanSeeTableRecords(Invoice::where('consumer_unit_id', $this->receiver->id)->get())
            ->assertCountTableRecords(Invoice::count());
    }

    public function test_all_units_permission_ignores_the_unit_list(): void
    {
        $role = Role::findOrCreate('Auditor', 'web')->syncPermissions(['painel.ver', 'unidades.ver', 'todas_unidades.ver']);
        $this->actingAs($this->userWith($role->name));

        $this->assertSame(ConsumerUnit::withoutGlobalScopes()->count(), ConsumerUnit::count());
        $this->assertFalse(auth()->user()->canEditUnit($this->generator->id));
    }

    public function test_editing_requires_permission_and_edit_access_to_the_unit(): void
    {
        $generation = SolarGeneration::create(['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 10]);

        $readOnly = $this->userWith('Gestor', [$this->generator->id => false]);
        $this->assertTrue($readOnly->can('view', $generation));
        $this->assertFalse($readOnly->can('update', $generation));

        $editor = $this->userWith('Gestor', [$this->generator->id => true]);
        $this->assertTrue($editor->can('update', $generation));

        $viewerWithEditFlag = $this->userWith('Consulta', [$this->generator->id => true]);
        $this->assertFalse($viewerWithEditFlag->can('update', $generation), 'perfil sem geracao.editar');

        $this->assertFalse($this->userWith('Gestor', [$this->receiver->id => true])->can('view', $generation));
    }

    public function test_generation_form_offers_only_editable_generators_and_actions_follow_permission(): void
    {
        $this->actingAs($this->userWith('Gestor', [$this->generator->id => true]));
        Livewire::test(ManageSolarGenerations::class)
            ->assertActionVisible('importarProducaoDiaria')
            ->callAction('create', ['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 5])
            ->assertHasNoActionErrors();

        $this->actingAs($this->userWith('Consulta', [$this->generator->id => true]));
        Livewire::test(ManageSolarGenerations::class)
            ->assertActionHidden('importarProducaoDiaria')
            ->assertActionHidden('create');
    }

    public function test_pdf_import_action_needs_the_import_permission(): void
    {
        $this->actingAs($this->userWith('Consulta', [$this->generator->id => false]));
        Livewire::test(ListInvoices::class)->assertActionHidden('importarPdfs');

        $this->actingAs($this->userWith('Gestor', [$this->generator->id => true]));
        Livewire::test(ListInvoices::class)->assertActionVisible('importarPdfs');
    }

    public function test_panel_and_original_pdf_respect_access(): void
    {
        $document = ImportedDocument::where('consumer_unit_id', $this->generator->id)->firstOrFail();

        $this->actingAs($this->userWith('Consulta', [$this->receiver->id => false]));
        $this->get(route('filament.admin.documents.file', $document))->assertNotFound(); // fora do escopo da unidade

        $noPanel = User::factory()->create();
        $this->actingAs($noPanel)->get('/admin')->assertForbidden();

        $this->actingAs($this->userWith('Consulta', [$this->generator->id => false]))->get('/admin')->assertOk();
    }

    public function test_generator_screens_are_hidden_from_users_with_only_receiver_units(): void
    {
        $resources = [SolarGenerationResource::class, SolarInstallmentResource::class, ImportedDocumentResource::class];

        $this->actingAs($this->userWith('Gestor', [$this->receiver->id => true]));
        foreach ($resources as $resource) {
            $this->assertFalse($resource::canViewAny(), $resource);
        }
        $this->get('/admin/solar-generations')->assertForbidden();

        $this->actingAs($this->userWith('Gestor', [$this->receiver->id => true, $this->generator->id => false]));
        foreach ($resources as $resource) {
            $this->assertTrue($resource::canViewAny(), $resource);
        }
    }

    public function test_user_and_role_management_require_permissions(): void
    {
        $this->actingAs($this->userWith('Gestor'));
        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/roles')->assertForbidden();

        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin)->get('/admin/users')->assertOk();
        $this->get('/admin/roles')->assertOk();
    }

    public function test_admin_creates_user_with_role_and_unit_access(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Maria',
                'email' => 'maria@example.com',
                'password' => 'segredo123',
                'roles' => [Role::findByName('Consulta')->id],
                'unitAccesses' => [['consumer_unit_id' => $this->receiver->id, 'can_edit' => false]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $maria = User::where('email', 'maria@example.com')->firstOrFail();
        $this->assertTrue(Hash::check('segredo123', $maria->password));
        $this->assertTrue($maria->hasRole('Consulta'));
        $this->assertSame([$this->receiver->id], $maria->viewableUnitIds());
    }

    public function test_admin_creates_and_edits_roles_with_permissions(): void
    {
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Financeiro', 'grouped' => ['painel' => ['painel.ver'], 'parcelas_das_placas' => ['parcelas.ver', 'parcelas.editar']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $role = Role::findByName('Financeiro');
        $this->assertEqualsCanonicalizing(['painel.ver', 'parcelas.ver', 'parcelas.editar'], $role->permissions->pluck('name')->all());

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormSet(fn ($state) => $state['grouped']['painel'] === ['painel.ver'])
            ->fillForm(['grouped' => ['painel' => ['painel.ver'], 'parcelas_das_placas' => []]])
            ->call('save');

        $this->assertSame(['painel.ver'], $role->fresh()->permissions->pluck('name')->all());
    }

    public function test_administrator_role_and_roles_in_use_cannot_be_deleted(): void
    {
        $admin = User::factory()->administrator()->create();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', Role::findByName(AccessControl::ADMIN_ROLE)));
        $this->userWith('Consulta');
        $this->assertFalse($admin->can('delete', Role::findByName('Consulta')));
        $this->assertTrue($admin->can('delete', Role::findOrCreate('Vazio', 'web')));
        $this->assertFalse($admin->can('delete', $admin), 'não exclui a si mesmo');

        Livewire::test(ListRoles::class)->assertOk();
        Livewire::test(ListUsers::class)->assertOk();
    }

    public function test_profile_page_is_available_in_the_user_menu(): void
    {
        $this->actingAs($this->userWith('Consulta'))->get('/admin/profile')->assertOk();
    }
}
