<?php

namespace Tests\Feature;

use App\Models\ConsumerUnit;
use App\Models\ImportedDocument;
use App\Models\Invoice;
use App\Models\SolarDailyProduction;
use App\Models\SolarGeneration;
use App\Models\UnitAccess;
use App\Models\UnitRequest;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use ZipArchive;

class MobileAdminApiTest extends TestCase
{
    use RefreshDatabase;

    private ConsumerUnit $generator;

    private ConsumerUnit $receiver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $importer = app(DocumentImporter::class);
        foreach (['demonstrativo_2026_11.txt', 'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt'] as $file) {
            $importer->importText(file_get_contents(__DIR__.'/../Fixtures/'.$file));
        }

        $this->generator = ConsumerUnit::where('role', 'generator')->firstOrFail();
        $this->receiver = ConsumerUnit::where('role', 'receiver')->whereHas('invoices')->firstOrFail();
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        Sanctum::actingAs($user);

        return $user;
    }

    private function spreadsheet(string $from, string $to, float $kwhPerDay = 10): UploadedFile
    {
        $strings = ['Nome da Instalação', 'Tempo atualizado', 'Hoje Produção(kWh)', 'Rendimento(BRL)', 'Usina'];
        $rows = '<row r="1">'.collect([0, 1, 2, 3])->map(fn ($i, $k) => '<c r="'.chr(65 + $k).'1" t="s"><v>'.$i.'</v></c>')->implode('').'</row>';
        $line = 2;
        for ($d = Carbon::parse($from); $d->lte(Carbon::parse($to)); $d->addDay(), $line++) {
            $strings[] = $d->format('Y/m/d');
            $dateIndex = count($strings) - 1;
            $strings[] = (string) $kwhPerDay;
            $kwhIndex = count($strings) - 1;
            $rows .= "<row r=\"{$line}\"><c r=\"A{$line}\" t=\"s\"><v>4</v></c><c r=\"B{$line}\" t=\"s\"><v>{$dateIndex}</v></c><c r=\"C{$line}\" t=\"s\"><v>{$kwhIndex}</v></c><c r=\"D{$line}\"><v>1.5</v></c></row>";
        }

        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('xl/sharedStrings.xml', '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.collect($strings)->map(fn ($s) => '<si><t>'.htmlspecialchars($s).'</t></si>')->implode('').'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$rows.'</sheetData></worksheet>');
        $zip->close();

        return UploadedFile::fake()->createWithContent('estatisticas.xlsx', file_get_contents($path));
    }

    public function test_generations_and_invoices_accept_a_month_period(): void
    {
        $this->actAs('Administrador');
        SolarGeneration::create(['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-09-01', 'generated_kwh' => 100, 'source' => 'manual']);
        SolarGeneration::create(['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-10-01', 'generated_kwh' => 200, 'source' => 'manual']);

        $this->getJson('/api/v1/generations?from=2026-10&to=2026-10')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.generated_kwh', 200);
        $this->getJson('/api/v1/generations?from=2026-09')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/invoices?from=2027-01')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/invoices?from=2026-11&to=2026-11')->assertOk()->assertJsonCount(Invoice::whereDate('reference_month', '2026-11-01')->count(), 'data');
        $this->getJson('/api/v1/invoices?from=11-2026')->assertStatus(422);
    }

    public function test_spreadsheet_import_saves_complete_months_and_lists_pending_ones(): void
    {
        $this->actAs('Administrador');

        $this->postJson('/api/v1/generations/import', ['consumer_unit_id' => $this->generator->id, 'file' => $this->spreadsheet('2026-10-10', '2026-11-20')])
            ->assertCreated()->assertJsonPath('data.updated', 1)->assertJsonPath('data.pending', []);
        $this->assertSame(310.0, SolarGeneration::sole()->generated_kwh);

        SolarGeneration::query()->delete();
        SolarDailyProduction::query()->delete();
        $this->postJson('/api/v1/generations/import', ['consumer_unit_id' => $this->generator->id, 'file' => $this->spreadsheet('2026-11-07', '2026-11-16')])
            ->assertCreated()->assertJsonPath('data.pending.0.month', '2026-11')->assertJsonPath('data.pending.0.complete', false);

        $this->getJson('/api/v1/generations/pending')->assertOk()->assertJsonCount(1, 'data');
        $this->postJson('/api/v1/generations/resolve', ['save' => ['2026-11']])->assertOk()->assertJsonPath('data.saved', 1);
        $this->assertSame(100.0, SolarGeneration::sole()->generated_kwh);
        $this->getJson('/api/v1/generations/pending')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_spreadsheet_import_rejects_receivers_bad_files_and_read_only_users(): void
    {
        $this->actAs('Administrador');
        $this->postJson('/api/v1/generations/import', ['consumer_unit_id' => $this->receiver->id, 'file' => $this->spreadsheet('2026-11-01', '2026-11-02')])->assertStatus(422);
        $this->postJson('/api/v1/generations/import', ['consumer_unit_id' => $this->generator->id, 'file' => UploadedFile::fake()->createWithContent('x.xlsx', 'lixo')])
            ->assertStatus(422);

        $this->actAs('Consulta');
        $this->postJson('/api/v1/generations/import', ['consumer_unit_id' => $this->generator->id, 'file' => $this->spreadsheet('2026-11-01', '2026-11-02')])->assertForbidden();
    }

    public function test_generation_can_be_deleted_only_with_edit_permission(): void
    {
        $generation = SolarGeneration::create(['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-09-01', 'generated_kwh' => 100, 'source' => 'manual']);

        $viewer = $this->actAs('Consulta');
        UnitAccess::create(['user_id' => $viewer->id, 'consumer_unit_id' => $this->generator->id, 'can_edit' => false]);
        $this->deleteJson("/api/v1/generations/{$generation->id}")->assertForbidden();

        $this->actAs('Administrador');
        $this->deleteJson("/api/v1/generations/{$generation->id}")->assertNoContent();
        $this->assertSame(0, SolarGeneration::count());
    }

    public function test_pdf_import_reports_failures_and_requires_permission(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('invalido.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");

        $this->actAs('Consulta');
        $this->postJson('/api/v1/documents/import', ['files' => [$pdf]])->assertForbidden();

        $this->actAs('Administrador');
        $this->postJson('/api/v1/documents/import', ['files' => [$pdf]])
            ->assertCreated()->assertJsonPath('data.imported', 0)->assertJsonPath('data.failures.0.file', 'invalido.pdf');
        $this->postJson('/api/v1/documents/import', ['files' => [UploadedFile::fake()->createWithContent('a.txt', 'texto')]])->assertStatus(422);
        $this->postJson('/api/v1/documents/import', [])->assertStatus(422);
    }

    public function test_documents_list_and_delete(): void
    {
        $this->actAs('Administrador');
        $document = ImportedDocument::firstOrFail();

        $this->getJson('/api/v1/documents')->assertOk()->assertJsonStructure(['data' => [['id', 'original_name', 'status', 'unit_name']]]);
        $this->deleteJson("/api/v1/documents/{$document->id}")->assertNoContent();
        $this->assertModelMissing($document);
    }

    public function test_user_crud_with_roles_and_unit_access(): void
    {
        $admin = $this->actAs('Administrador');

        $id = $this->postJson('/api/v1/admin/users', [
            'name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'segredo123',
            'roles' => ['Consulta'], 'unit_accesses' => [['consumer_unit_id' => $this->receiver->id, 'can_edit' => false]],
        ])->assertCreated()->assertJsonPath('data.roles.0', 'Consulta')->assertJsonPath('data.unit_accesses.0.can_edit', false)->json('data.id');

        $this->putJson("/api/v1/admin/users/{$id}", [
            'name' => 'Maria S.', 'email' => 'maria@example.com', 'roles' => ['Gestor'],
            'unit_accesses' => [['consumer_unit_id' => $this->receiver->id, 'can_edit' => true], ['consumer_unit_id' => $this->generator->id]],
        ])->assertOk()->assertJsonPath('data.roles.0', 'Gestor')->assertJsonCount(2, 'data.unit_accesses');

        $this->putJson("/api/v1/admin/users/{$id}", ['name' => 'Maria S.', 'email' => 'maria@example.com', 'unit_accesses' => []])
            ->assertOk()->assertJsonCount(0, 'data.unit_accesses')->assertJsonPath('data.roles.0', 'Gestor');

        $this->postJson('/api/v1/admin/users', ['name' => 'X', 'email' => 'maria@example.com', 'password' => 'segredo123'])->assertStatus(422);
        $this->deleteJson("/api/v1/admin/users/{$admin->id}")->assertForbidden();
        $this->deleteJson("/api/v1/admin/users/{$id}")->assertNoContent();
    }

    public function test_user_admin_endpoints_require_the_admin_permission(): void
    {
        $this->actAs('Gestor');

        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/admin/roles')->assertForbidden();
        $this->getJson('/api/v1/admin/unit-requests')->assertForbidden();
    }

    public function test_role_management_protects_the_administrator_profile(): void
    {
        $this->actAs('Administrador');

        $this->getJson('/api/v1/admin/roles')->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'permissions', 'users_count', 'protected']]]);
        $this->getJson('/api/v1/admin/permissions')->assertOk()->assertJsonStructure(['data' => [['group', 'permissions' => [['name', 'label']]]]]);

        $id = $this->postJson('/api/v1/admin/roles', ['name' => 'Financeiro', 'permissions' => ['painel.ver', 'pagamentos.ver']])
            ->assertCreated()->assertJsonCount(2, 'data.permissions')->json('data.id');
        $this->postJson('/api/v1/admin/roles', ['name' => 'Financeiro'])->assertStatus(422);
        $this->postJson('/api/v1/admin/roles', ['name' => 'Outro', 'permissions' => ['inexistente.x']])->assertStatus(422);

        $this->putJson("/api/v1/admin/roles/{$id}", ['name' => 'Financeiro 2', 'permissions' => ['painel.ver']])
            ->assertOk()->assertJsonPath('data.name', 'Financeiro 2')->assertJsonCount(1, 'data.permissions');

        $adminRole = Role::where('name', 'Administrador')->firstOrFail();
        $this->putJson("/api/v1/admin/roles/{$adminRole->id}", ['name' => 'Renomeado', 'permissions' => []])->assertOk();
        $this->assertSame('Administrador', $adminRole->fresh()->name);
        $this->assertGreaterThan(5, $adminRole->fresh()->permissions()->count());
        $this->deleteJson("/api/v1/admin/roles/{$adminRole->id}")->assertForbidden();

        $this->deleteJson("/api/v1/admin/roles/{$id}")->assertNoContent();
    }

    public function test_unit_requests_can_be_approved_or_rejected(): void
    {
        $this->actAs('Administrador');
        $user = User::factory()->create();
        $user->assignRole('Consulta');
        $approve = UnitRequest::create(['user_id' => $user->id, 'consumer_unit_id' => $this->receiver->id, 'status' => UnitRequest::PENDING]);
        $reject = UnitRequest::create(['user_id' => $user->id, 'consumer_unit_id' => $this->generator->id, 'status' => UnitRequest::PENDING]);

        $this->getJson('/api/v1/admin/unit-requests?status=pending')->assertOk()->assertJsonCount(2, 'data');

        $this->postJson("/api/v1/admin/unit-requests/{$approve->id}/approve", ['can_edit' => true])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->assertTrue($user->unitAccesses()->where('consumer_unit_id', $this->receiver->id)->value('can_edit'));

        $this->postJson("/api/v1/admin/unit-requests/{$reject->id}/reject", ['note' => 'Sem necessidade'])->assertOk()->assertJsonPath('data.decision_note', 'Sem necessidade');
        $this->postJson("/api/v1/admin/unit-requests/{$reject->id}/approve")->assertStatus(422);
    }

    public function test_pending_user_gets_the_requested_unit_granted(): void
    {
        $this->actAs('Administrador');
        $user = User::factory()->create(['status' => User::PENDING]);
        UnitRequest::create(['user_id' => $user->id, 'consumer_unit_id' => $this->receiver->id, 'status' => UnitRequest::PENDING]);

        $this->postJson("/api/v1/admin/users/{$user->id}/grant-requested-unit")
            ->assertOk()->assertJsonPath('data.status', User::ACTIVE)->assertJsonPath('data.unit_accesses.0.consumer_unit_id', $this->receiver->id);
        $this->postJson("/api/v1/admin/users/{$user->id}/grant-requested-unit")->assertStatus(422);
    }

    public function test_unit_crud(): void
    {
        $this->actAs('Administrador');

        $id = $this->postJson('/api/v1/units', ['name' => 'Casa Nova', 'role' => 'receiver', 'uc_number' => '123.456.789-00', 'active' => true, 'city' => 'Naviraí'])
            ->assertCreated()->assertJsonPath('data.uc_number', '12345678900')->assertJsonPath('data.city', 'Naviraí')->json('data.id');
        $this->postJson('/api/v1/units', ['name' => 'Dup', 'role' => 'receiver', 'uc_number' => '12345678900'])->assertStatus(422);

        $this->putJson("/api/v1/units/{$id}", ['name' => 'Casa Nova 2', 'role' => 'receiver', 'uc_number' => '12345678900', 'bills_solar_cost' => false])
            ->assertOk()->assertJsonPath('data.name', 'Casa Nova 2')->assertJsonPath('data.bills_solar_cost', false);
        $this->getJson("/api/v1/units/{$id}")->assertOk()->assertJsonStructure(['data' => ['holder_name', 'notes', 'counts_in_generator_cost']]);
        $this->deleteJson("/api/v1/units/{$id}")->assertNoContent();

        $this->actAs('Consulta');
        $this->postJson('/api/v1/units', ['name' => 'X', 'role' => 'receiver', 'uc_number' => '999999'])->assertForbidden();
    }

    public function test_installment_crud_only_for_generators(): void
    {
        $this->actAs('Administrador');

        $id = $this->postJson('/api/v1/admin/installments', ['consumer_unit_id' => $this->generator->id, 'amount' => 850.5, 'start_month' => '2026-01', 'description' => 'Financiamento'])
            ->assertCreated()->assertJsonPath('data.start_month', '2026-01-01')->assertJsonPath('data.end_month', null)->json('data.id');
        $this->postJson('/api/v1/admin/installments', ['consumer_unit_id' => $this->receiver->id, 'amount' => 1, 'start_month' => '2026-01'])->assertStatus(422);
        $this->postJson('/api/v1/admin/installments', ['consumer_unit_id' => $this->generator->id, 'amount' => 1, 'start_month' => '2026-05', 'end_month' => '2026-01'])->assertStatus(422);

        $this->putJson("/api/v1/admin/installments/{$id}", ['consumer_unit_id' => $this->generator->id, 'amount' => 900, 'start_month' => '2026-01', 'end_month' => '2026-12'])
            ->assertOk()->assertJsonPath('data.amount', 900)->assertJsonPath('data.end_month', '2026-12-01');
        $this->getJson('/api/v1/admin/installments')->assertOk()->assertJsonCount(1, 'data');
        $this->deleteJson("/api/v1/admin/installments/{$id}")->assertNoContent();
    }

    public function test_bulk_decision_on_unit_requests_skips_the_ones_already_answered(): void
    {
        $this->actAs('Administrador');
        $user = User::factory()->create();
        $other = User::factory()->create();
        $a = UnitRequest::create(['user_id' => $user->id, 'consumer_unit_id' => $this->receiver->id, 'status' => UnitRequest::PENDING]);
        $b = UnitRequest::create(['user_id' => $other->id, 'consumer_unit_id' => $this->receiver->id, 'status' => UnitRequest::PENDING]);
        $answered = UnitRequest::create(['user_id' => $user->id, 'consumer_unit_id' => $this->generator->id, 'status' => UnitRequest::REJECTED]);

        $this->postJson('/api/v1/admin/unit-requests/bulk', ['ids' => [$a->id, $answered->id], 'action' => 'approve'])
            ->assertOk()->assertJsonPath('data.done', 1)->assertJsonPath('data.skipped', 1);
        $this->assertSame(UnitRequest::APPROVED, $a->fresh()->status);
        $this->assertFalse($user->unitAccesses()->where('consumer_unit_id', $this->receiver->id)->value('can_edit'));

        $this->postJson('/api/v1/admin/unit-requests/bulk', ['ids' => [$b->id], 'action' => 'reject', 'note' => 'Sem necessidade'])
            ->assertOk()->assertJsonPath('data.done', 1);
        $this->assertSame('Sem necessidade', $b->fresh()->decision_note);

        $this->postJson('/api/v1/admin/unit-requests/bulk', ['ids' => [], 'action' => 'approve'])->assertStatus(422);

        $this->actAs('Gestor');
        $this->postJson('/api/v1/admin/unit-requests/bulk', ['ids' => [$a->id], 'action' => 'reject'])->assertForbidden();
    }

    public function test_user_updates_own_profile_and_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('antiga123')]);
        $user->assignRole('Consulta');
        $other = User::factory()->create(['email' => 'outro@example.com']);

        $token = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'antiga123'])->assertOk()->json('token');
        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'antiga123'])->assertOk();
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->putJson('/api/v1/me', ['name' => 'Novo Nome', 'email' => 'novo@example.com'], $headers)
            ->assertOk()->assertJsonPath('user.name', 'Novo Nome')->assertJsonPath('user.email', 'novo@example.com');
        $this->putJson('/api/v1/me', ['name' => 'X', 'email' => $other->email], $headers)->assertStatus(422);

        $this->putJson('/api/v1/me/password', ['current_password' => 'errada', 'password' => 'nova12345', 'password_confirmation' => 'nova12345'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/me/password', ['current_password' => 'antiga123', 'password' => 'curta', 'password_confirmation' => 'curta'], $headers)->assertStatus(422);
        $this->putJson('/api/v1/me/password', ['current_password' => 'antiga123', 'password' => 'nova12345', 'password_confirmation' => 'nova12345'], $headers)->assertNoContent();

        $this->assertSame(1, $user->tokens()->count());
        $this->postJson('/api/v1/login', ['email' => 'novo@example.com', 'password' => 'nova12345'])->assertOk();
    }

    public function test_invoice_original_pdf_can_be_downloaded_by_who_can_see_it(): void
    {
        $invoice = Invoice::where('consumer_unit_id', $this->receiver->id)->firstOrFail();
        $document = $invoice->document;
        $this->assertNotNull($document, 'a fatura importada deve ter o documento de origem');
        $document->update(['path' => 'documents/teste.pdf']);
        Storage::disk('local')->put('documents/teste.pdf', "%PDF-1.4\nfake");

        $viewer = $this->actAs('Consulta');
        UnitAccess::create(['user_id' => $viewer->id, 'consumer_unit_id' => $this->receiver->id, 'can_edit' => false]);

        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('data.0.has_file', true);
        $this->get("/api/v1/invoices/{$invoice->id}/file")->assertOk()->assertHeader('Content-Type', 'application/pdf');

        $document->update(['path' => null]);
        $this->getJson('/api/v1/invoices')->assertOk()->assertJsonPath('data.0.has_file', false);
        $this->getJson("/api/v1/invoices/{$invoice->id}/file")->assertNotFound();
    }

    public function test_invoice_file_is_hidden_from_users_without_access_to_the_unit(): void
    {
        $invoice = Invoice::where('consumer_unit_id', $this->receiver->id)->firstOrFail();

        $this->actAs('Consulta');
        $this->getJson("/api/v1/invoices/{$invoice->id}/file")->assertNotFound();
    }

    public function test_role_in_use_by_users_cannot_be_deleted(): void
    {
        $this->actAs('Administrador');
        $role = Role::where('name', 'Gestor')->firstOrFail();
        User::factory()->create()->assignRole('Gestor');

        $this->getJson('/api/v1/admin/roles')->assertOk()->assertJsonFragment(['name' => 'Gestor', 'users_count' => 1]);
        $this->deleteJson("/api/v1/admin/roles/{$role->id}")->assertForbidden();
    }
}
