<?php

namespace Tests\Feature;

use App\Models\ConsumerUnit;
use App\Models\RateioPayment;
use App\Models\SolarGeneration;
use App\Models\SolarInstallment;
use App\Models\UnitAccess;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileRateioApiTest extends TestCase
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
        SolarGeneration::create(['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 1000]);
        SolarInstallment::create(['consumer_unit_id' => $this->generator->id, 'amount' => 500, 'start_month' => '2026-11-01', 'end_month' => '2026-11-01']);
    }

    /** @param array<int, bool> $units unidade => pode editar */
    private function headers(string $role, array $units = []): array
    {
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);
        $user->assignRole($role);
        foreach ($units as $id => $edit) {
            UnitAccess::create(['user_id' => $user->id, 'consumer_unit_id' => $id, 'can_edit' => $edit]);
        }

        $token = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'segredo123'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer '.$token];
    }

    private function fakeTesseract(string $output): string
    {
        $script = tempnam(sys_get_temp_dir(), 'tess');
        file_put_contents($script, "#!/bin/sh\ncat <<'EOT'\n{$output}\nEOT\n");
        chmod($script, 0755);

        return $script;
    }

    public function test_summary_compares_due_with_payments_for_visible_units_only(): void
    {
        $h = $this->headers('Consulta', [$this->receiver->id => false]);
        RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1]);

        $rows = $this->getJson('/api/v1/rateio/summary', $h)->assertOk()
            ->assertJsonStructure(['data' => [['unit_id', 'unit', 'month', 'due', 'paid', 'balance', 'status', 'payments']]])->json('data');

        $this->assertSame([$this->receiver->id], array_values(array_unique(array_column($rows, 'unit_id'))));
        $december = collect($rows)->firstWhere('month', '2026-12');
        $this->assertSame('partial', $december['status']);
        $this->assertEquals(1, $december['paid']);

        $this->getJson('/api/v1/rateio/summary?status=paid', $h)->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/rateio/summary?status=xyz', $h)->assertStatus(422);
    }

    public function test_manager_registers_manual_payment_and_lists_it(): void
    {
        $h = $this->headers('Gestor', [$this->receiver->id => true]);

        $this->postJson('/api/v1/rateio/payments', [
            'consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12', 'amount' => 11.33,
            'payer_name' => 'Fulano', 'transaction_id' => 'E999', 'paid_at' => '2026-11-21 19:36:25',
        ], $h)->assertCreated()
            ->assertJsonPath('data.reference_month', '2026-12-01')
            ->assertJsonPath('data.source', 'manual')
            ->assertJsonPath('data.has_receipt', false);

        $this->getJson('/api/v1/rateio/payments?unit_id='.$this->receiver->id.'&month=2026-12', $h)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.amount', 11.33);

        $this->postJson('/api/v1/rateio/payments', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12', 'amount' => 3, 'transaction_id' => 'E999'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('transaction_id');
        $this->postJson('/api/v1/rateio/payments', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12'], $h)
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/rateio/payments', ['consumer_unit_id' => $this->generator->id, 'reference_month' => '2026-12', 'amount' => 1], $h)
            ->assertForbidden(); // sem acesso de edição à geradora
    }

    public function test_receipt_fills_missing_fields_and_can_be_downloaded(): void
    {
        config(['geracao.tesseract_binary' => $this->fakeTesseract(file_get_contents(__DIR__.'/../Fixtures/comprovante_pix_nubank.txt'))]);
        $h = $this->headers('Gestor', [$this->receiver->id => true]);

        $this->postJson('/api/v1/rateio/receipts/read', ['receipt' => UploadedFile::fake()->image('c.png', 400, 300)], $h)
            ->assertOk()
            ->assertJsonPath('data.readable', true)
            ->assertJsonPath('data.amount', 1211.33)
            ->assertJsonPath('data.payer_name', 'Paulo Rogério Vitorino Barbosa')
            ->assertJsonPath('data.already_registered', false);

        $id = $this->postJson('/api/v1/rateio/payments', [
            'consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01',
            'receipt' => UploadedFile::fake()->image('c.png', 400, 300),
        ], $h)->assertCreated()
            ->assertJsonPath('data.amount', 1211.33)
            ->assertJsonPath('data.transaction_id', 'E18236120202611220010s02cad3c0ad')
            ->assertJsonPath('data.source', 'comprovante')
            ->assertJsonPath('data.has_receipt', true)
            ->json('data.id');

        $this->get("/api/v1/rateio/payments/{$id}/receipt", $h)->assertOk();

        $this->postJson('/api/v1/rateio/receipts/read', ['receipt' => UploadedFile::fake()->image('c.png', 400, 300)], $h)
            ->assertJsonPath('data.already_registered', true);
    }

    public function test_receipt_without_ocr_requires_the_amount(): void
    {
        config(['geracao.tesseract_binary' => null]);
        $h = $this->headers('Gestor', [$this->receiver->id => true]);

        $this->postJson('/api/v1/rateio/payments', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12', 'receipt' => UploadedFile::fake()->image('c.png')], $h)
            ->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/rateio/receipts/read', ['receipt' => UploadedFile::fake()->image('c.png')], $h)
            ->assertOk()->assertJsonPath('data.readable', false)->assertJsonPath('data.ocr_enabled', false);
        $this->assertSame(0, RateioPayment::count());
    }

    public function test_viewer_cannot_write_and_cannot_reach_other_units(): void
    {
        $other = ConsumerUnit::create(['name' => 'Outra', 'uc_number' => '999', 'role' => 'receiver', 'bills_solar_cost' => true]);
        $mine = RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1]);
        $theirs = RateioPayment::create(['consumer_unit_id' => $other->id, 'reference_month' => '2026-12-01', 'amount' => 2]);
        $h = $this->headers('Consulta', [$this->receiver->id => false]);

        $this->getJson('/api/v1/rateio/payments', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->postJson('/api/v1/rateio/payments', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12', 'amount' => 1], $h)->assertForbidden();
        $this->postJson('/api/v1/rateio/receipts/read', ['receipt' => UploadedFile::fake()->image('c.png')], $h)->assertForbidden();
        $this->deleteJson("/api/v1/rateio/payments/{$mine->id}", [], $h)->assertForbidden();
        $this->deleteJson("/api/v1/rateio/payments/{$theirs->id}", [], $h)->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/rateio/payments')->assertUnauthorized();
    }

    public function test_manager_deletes_payment_and_its_file(): void
    {
        Storage::disk('local')->put('rateio-comprovantes/a.pdf', 'x');
        $payment = RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1, 'file_path' => 'rateio-comprovantes/a.pdf']);
        $h = $this->headers('Gestor', [$this->receiver->id => true]);

        $this->deleteJson("/api/v1/rateio/payments/{$payment->id}", [], $h)->assertNoContent();

        $this->assertSame(0, RateioPayment::count());
        Storage::disk('local')->assertMissing('rateio-comprovantes/a.pdf');
    }

    public function test_tokens_expire_and_login_reports_the_expiration(): void
    {
        config(['sanctum.expiration' => 60 * 24 * 30]);
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);
        $user->assignRole('Consulta');

        $login = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'segredo123'])->assertOk();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($login->json('expires_at')), 5);
        $headers = ['Authorization' => 'Bearer '.$login->json('token')];

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_tokens_do_not_expire_when_expiration_is_disabled(): void
    {
        config(['sanctum.expiration' => null]);
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'segredo123'])
            ->assertOk()->assertJsonPath('expires_at', null);
    }
}
