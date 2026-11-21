<?php

namespace Tests\Feature;

use App\Filament\Pages\PendingApproval;
use App\Filament\Pages\RegisterUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ConsumerUnit;
use App\Models\ImportedDocument;
use App\Models\UnitAccess;
use App\Models\UnitRequest;
use App\Models\User;
use App\Notifications\PanelNotification;
use App\Services\Documents\DocumentImporter;
use App\Services\Notifications\ImportNotifier;
use App\Support\Mask;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

class RegistrationAndNotificationsTest extends TestCase
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

    private function register(): User
    {
        Livewire::test(RegisterUser::class)
            ->fillForm(['name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'segredo123', 'passwordConfirmation' => 'segredo123'])
            ->call('register')
            ->assertHasNoFormErrors();

        return User::where('email', 'maria@example.com')->firstOrFail();
    }

    public function test_registration_creates_a_pending_user_with_the_consulta_role_and_notifies_admins(): void
    {
        $admin = User::factory()->administrator()->create();
        Notification::fake();

        $this->get('/admin/register')->assertOk();
        $maria = $this->register();

        $this->assertTrue($maria->isPending());
        $this->assertTrue($maria->hasRole('Consulta'));
        $this->assertAuthenticatedAs($maria);

        Notification::assertSentTo($admin, PanelNotification::class, fn ($n, $channels) => $n->title === 'Novo usuário criado'
            && str_contains($n->body, 'maria@example.com') && $channels === ['database', 'mail']);
    }

    public function test_pending_user_is_confined_to_the_request_page_and_has_no_permissions(): void
    {
        $this->actingAs($pending = User::factory()->create(['status' => User::PENDING]));
        $pending->assignRole('Consulta');
        UnitAccess::withoutEvents(fn () => UnitAccess::create(['user_id' => $pending->id, 'consumer_unit_id' => $this->receiver->id]));

        $this->assertFalse($pending->can('painel.ver'));
        $this->get('/admin')->assertRedirect(PendingApproval::getUrl());
        $this->get('/admin/invoices')->assertRedirect(PendingApproval::getUrl());
        $this->get(PendingApproval::getUrl())->assertOk();
    }

    public function test_pending_user_requests_a_unit_and_admins_are_notified(): void
    {
        $admin = User::factory()->administrator()->create();
        $pending = User::factory()->create(['status' => User::PENDING]);
        $pending->assignRole('Consulta');
        $this->actingAs($pending);
        Notification::fake();

        Livewire::test(PendingApproval::class)
            ->call('openRequestModal')
            ->assertActionMounted('requestUnit') // modal abre sozinho enquanto não há solicitação
            ->assertDontSee('917.358.051-98')
            ->setActionData(['consumer_unit_id' => $this->generator->id, 'message' => 'Preciso acompanhar'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $request = UnitRequest::sole();
        $this->assertSame($this->generator->id, $request->consumer_unit_id);
        $this->assertTrue($pending->fresh()->isPending());

        Notification::assertSentTo($admin, PanelNotification::class, fn ($n) => $n->title === 'Solicitação de acesso a unidade'
            && str_contains($n->body, $this->generator->name) && str_contains($n->body, 'Preciso acompanhar'));
    }

    public function test_assigning_a_unit_activates_the_user_automatically(): void
    {
        $pending = User::factory()->create(['status' => User::PENDING]);
        $pending->assignRole('Consulta');
        $request = UnitRequest::create(['user_id' => $pending->id, 'consumer_unit_id' => $this->receiver->id]);
        Notification::fake();

        UnitAccess::create(['user_id' => $pending->id, 'consumer_unit_id' => $this->receiver->id, 'can_edit' => false]);

        $this->assertFalse($pending->fresh()->isPending());
        $this->assertSame(UnitRequest::APPROVED, $request->fresh()->status);
        Notification::assertSentTo($pending, PanelNotification::class, fn ($n) => $n->title === 'Acesso liberado');

        $this->actingAs($pending->fresh())->get('/admin')->assertOk();
    }

    public function test_admin_can_grant_the_requested_unit_from_the_users_table(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $pending = User::factory()->create(['status' => User::PENDING]);
        UnitRequest::create(['user_id' => $pending->id, 'consumer_unit_id' => $this->receiver->id]);

        Livewire::test(ListUsers::class)
            ->assertTableActionVisible('grantRequestedUnit', $pending)
            ->callTableAction('grantRequestedUnit', $pending);

        $this->assertFalse($pending->fresh()->isPending());
        $this->assertSame([$this->receiver->id], $pending->fresh()->viewableUnitIds());
    }

    public function test_import_notifies_admins_and_users_with_access_but_not_the_actor_or_others(): void
    {
        $actor = User::factory()->administrator()->create();
        $otherAdmin = User::factory()->administrator()->create();
        $viewer = User::factory()->create();
        $viewer->assignRole('Consulta');
        UnitAccess::create(['user_id' => $viewer->id, 'consumer_unit_id' => $this->generator->id]);
        $stranger = User::factory()->create();
        $stranger->assignRole('Consulta');
        UnitAccess::create(['user_id' => $stranger->id, 'consumer_unit_id' => $this->receiver->id]);
        $this->actingAs($actor);
        Notification::fake();

        ImportNotifier::notify(ImportedDocument::where('consumer_unit_id', $this->generator->id)->get());

        Notification::assertSentTo($otherAdmin, PanelNotification::class, fn ($n) => str_contains($n->body, $this->generator->name));
        Notification::assertSentTo($viewer, PanelNotification::class);
        Notification::assertNotSentTo($stranger, PanelNotification::class);
        Notification::assertNotSentTo($actor, PanelNotification::class);
    }

    public function test_masks_hide_part_of_the_uc_and_address(): void
    {
        $this->assertSame('917.***.***-98', Mask::uc('917.358.051-98'));
        $this->assertSame('RUA VAN*** JOA***, **', Mask::address('RUA VANILSON JOAO, 65'));
        $this->assertSame('endereço não informado', Mask::address(null));
    }
}
