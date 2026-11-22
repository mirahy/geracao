<?php

namespace Tests\Feature;

use App\Filament\Resources\RateioPayments\Pages\ManageRateioPayments;
use App\Models\ConsumerUnit;
use App\Models\RateioPayment;
use App\Models\SolarGeneration;
use App\Models\SolarInstallment;
use App\Models\UnitAccess;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use App\Services\Rateio\PaymentReceiptParser;
use App\Services\Rateio\PaymentReceiptReader;
use App\Services\Rateio\RateioLedger;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RateioPaymentsTest extends TestCase
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

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    private function userWith(string $role, array $units = []): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        foreach ($units as $unitId => $canEdit) {
            UnitAccess::create(['user_id' => $user->id, 'consumer_unit_id' => $unitId, 'can_edit' => $canEdit]);
        }

        return $user;
    }

    public function test_parser_reads_a_picpay_receipt(): void
    {
        $data = app(PaymentReceiptParser::class)->parse($this->fixture('comprovante_pix_picpay.txt'));

        $this->assertSame(11.33, $data['amount']);
        $this->assertSame('2026-11-21 19:36:25', $data['paid_at']->format('Y-m-d H:i:s'));
        $this->assertSame('E22896431202611212236jLM3c42834d', $data['transaction_id']);
        $this->assertSame('MARIA APARECIDA DA SILVA ROCHA', $data['payer_name']);
    }

    public function test_parser_reads_a_nubank_receipt(): void
    {
        $data = app(PaymentReceiptParser::class)->parse($this->fixture('comprovante_pix_nubank.txt'));

        $this->assertSame(1211.33, $data['amount']);
        $this->assertSame('2026-11-21 21:10:26', $data['paid_at']->format('Y-m-d H:i:s'));
        $this->assertSame('E18236120202611220010s02cad3c0ad', $data['transaction_id']);
        $this->assertSame('Paulo Rogério Vitorino Barbosa', $data['payer_name']);
    }

    public function test_parser_returns_nulls_for_unknown_text(): void
    {
        $this->assertSame(
            ['amount' => null, 'paid_at' => null, 'transaction_id' => null, 'payer_name' => null],
            app(PaymentReceiptParser::class)->parse('texto qualquer sem dados')
        );
    }

    public function test_ledger_compares_due_with_partial_payments(): void
    {
        $ledger = app(RateioLedger::class);
        $row = fn () => $ledger->rows([$this->receiver->id])->firstWhere('month', '2026-12');

        $due = $row()['due'];
        $this->assertGreaterThan(0, $due);
        $this->assertSame(RateioLedger::PENDING, $row()['status']);
        $this->assertSame('2026-12', $ledger->oldestOpenMonth($this->receiver->id));

        RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1]);
        $this->assertSame(RateioLedger::PARTIAL, $row()['status']);
        $this->assertEqualsWithDelta($due - 1, $row()['balance'], 0.001);

        RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => $due - 1]);
        $this->assertSame(RateioLedger::PAID, $row()['status']);
        $this->assertSame(2, $row()['payments']);
        $this->assertNull($ledger->oldestOpenMonth($this->receiver->id));

        RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 5]);
        $this->assertSame(RateioLedger::OVERPAID, $row()['status']);

        RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2027-03-01', 'amount' => 5]);
        $this->assertSame(RateioLedger::WITHOUT_CHARGE, $ledger->rows([$this->receiver->id])->firstWhere('month', '2027-03')['status']);
    }

    public function test_manager_registers_payment_with_receipt_and_blocks_duplicates(): void
    {
        $this->actingAs($this->userWith('Gestor', [$this->receiver->id => true]));
        $file = UploadedFile::fake()->create('comprovante.pdf', 10, 'application/pdf');

        Livewire::test(ManageRateioPayments::class)
            ->callAction('create', [
                'consumer_unit_id' => $this->receiver->id,
                'reference_month' => '2026-12-01',
                'amount' => 11.33,
                'payer_name' => 'Fulano',
                'transaction_id' => 'E123',
                'file_path' => $file,
            ])
            ->assertHasNoActionErrors();

        $payment = RateioPayment::firstOrFail();
        $this->assertSame('comprovante', $payment->source);
        $this->assertSame('comprovante.pdf', $payment->file_name);
        $this->assertNotNull($payment->created_by);
        Storage::disk('local')->assertExists($payment->file_path);

        Livewire::test(ManageRateioPayments::class)
            ->callAction('create', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 3, 'transaction_id' => 'E123'])
            ->assertHasActionErrors(['transaction_id']);

        Livewire::test(ManageRateioPayments::class)
            ->callAction('create', ['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 3])
            ->assertHasNoActionErrors();
        $this->assertSame(2, RateioPayment::count());
        $this->assertSame('manual', RateioPayment::latest('id')->first()->source);
    }

    public function test_deleting_a_payment_removes_its_receipt_file(): void
    {
        Storage::disk('local')->put('rateio-comprovantes/a.pdf', 'x');
        $payment = RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1, 'file_path' => 'rateio-comprovantes/a.pdf']);

        $payment->delete();

        Storage::disk('local')->assertMissing('rateio-comprovantes/a.pdf');
    }

    public function test_access_follows_permission_and_unit(): void
    {
        $other = ConsumerUnit::create(['name' => 'Outra', 'uc_number' => '999', 'role' => 'receiver', 'bills_solar_cost' => true]);
        Storage::disk('local')->put('rateio-comprovantes/a.pdf', 'x');
        $mine = RateioPayment::create(['consumer_unit_id' => $this->receiver->id, 'reference_month' => '2026-12-01', 'amount' => 1, 'file_path' => 'rateio-comprovantes/a.pdf', 'file_name' => 'a.pdf']);
        $theirs = RateioPayment::create(['consumer_unit_id' => $other->id, 'reference_month' => '2026-12-01', 'amount' => 2]);

        $viewer = $this->userWith('Consulta', [$this->receiver->id => false]);
        $this->actingAs($viewer);

        Livewire::test(ManageRateioPayments::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->assertActionHidden('create');

        $this->get(route('filament.admin.rateio-payments.file', $mine))->assertOk();
        $this->get(route('filament.admin.rateio-payments.file', $theirs))->assertNotFound();

        $this->assertFalse($viewer->can('update', $mine));
        $this->assertTrue($this->userWith('Gestor', [$this->receiver->id => true])->can('update', $mine));
    }

    public function test_seeder_adds_new_permissions_to_existing_roles_without_resetting_them(): void
    {
        $role = Role::findByName('Gestor', 'web');
        $role->revokePermissionTo('pagamentos.editar');
        $role->givePermissionTo('painel.ver');
        Permission::where('name', 'pagamentos.editar')->delete();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertTrue($role->fresh()->hasPermissionTo('pagamentos.editar'));
    }

    private function fakeTesseract(string $output): string
    {
        $script = tempnam(sys_get_temp_dir(), 'tess');
        file_put_contents($script, "#!/bin/sh\ncat <<'EOT'\n{$output}\nEOT\necho \"LANG=\$TESSDATA_PREFIX\" >&2\n");
        chmod($script, 0755);

        return $script;
    }

    public function test_image_receipt_is_ignored_when_ocr_is_not_configured(): void
    {
        config(['geracao.tesseract_binary' => null]);
        $image = UploadedFile::fake()->image('comprovante.png', 400, 300);

        $this->assertFalse(app(PaymentReceiptReader::class)->ocrEnabled());
        $this->assertNull(app(PaymentReceiptReader::class)->read($image->getRealPath()));
    }

    public function test_image_receipt_goes_through_ocr_when_configured(): void
    {
        config(['geracao.tesseract_binary' => $this->fakeTesseract($this->fixture('comprovante_pix_nubank.txt'))]);
        $image = UploadedFile::fake()->image('comprovante.png', 400, 300);

        $data = app(PaymentReceiptReader::class)->read($image->getRealPath());

        $this->assertSame(1211.33, $data['amount']);
        $this->assertSame('Paulo Rogério Vitorino Barbosa', $data['payer_name']);
        $this->assertSame('E18236120202611220010s02cad3c0ad', $data['transaction_id']);
    }

    public function test_ocr_failure_or_non_image_returns_null(): void
    {
        config(['geracao.tesseract_binary' => '/caminho/que/nao/existe']);
        $image = UploadedFile::fake()->image('comprovante.png', 400, 300);
        $this->assertNull(app(PaymentReceiptReader::class)->read($image->getRealPath()));

        $notImage = UploadedFile::fake()->createWithContent('x.txt', 'não é imagem');
        $this->assertNull(app(PaymentReceiptReader::class)->read($notImage->getRealPath()));
    }

    public function test_real_tesseract_reads_a_rendered_receipt(): void
    {
        $binary = trim((string) shell_exec('command -v tesseract'));
        if ($binary === '' || ! file_exists('/usr/share/tesseract-ocr/5/tessdata/por.traineddata')) {
            $this->markTestSkipped('Tesseract com o idioma português não está instalado.');
        }

        $image = imagecreatetruecolor(900, 260);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $black = imagecolorallocate($image, 0, 0, 0);
        $font = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf';
        if (! file_exists($font)) {
            $this->markTestSkipped('Fonte TrueType não disponível para montar a imagem.');
        }
        imagettftext($image, 28, 0, 20, 70, $black, $font, 'Valor R$ 37,50');
        imagettftext($image, 28, 0, 20, 140, $black, $font, '21 NOV 2026 - 21:10:26');
        $path = tempnam(sys_get_temp_dir(), 'rc').'.png';
        imagepng($image, $path);

        config(['geracao.tesseract_binary' => $binary]);
        $data = app(PaymentReceiptReader::class)->read($path);
        @unlink($path);

        $this->assertSame(37.5, $data['amount']);
        $this->assertSame('2026-11-21 21:10:26', $data['paid_at']->format('Y-m-d H:i:s'));
    }
}
