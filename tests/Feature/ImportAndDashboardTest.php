<?php

namespace Tests\Feature;

use App\Filament\Resources\ConsumerUnits\Pages\ViewConsumerUnit;
use App\Models\ConsumerUnit;
use App\Models\CreditTransfer;
use App\Models\EnergyStatement;
use App\Models\Invoice;
use App\Models\SolarGeneration;
use App\Models\SolarInstallment;
use App\Models\User;
use App\Services\Dashboard\EnergyDashboard;
use App\Services\Documents\DocumentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ImportAndDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(string $name): string
    {
        return file_get_contents(__DIR__.'/../Fixtures/'.$name);
    }

    private function importAll(): void
    {
        $importer = app(DocumentImporter::class);
        foreach ([
            'demonstrativo_2026_08.txt', 'demonstrativo_2026_09.txt', 'demonstrativo_2026_11.txt',
            'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt', 'fatura_recebedora_b_2026_11.txt',
        ] as $file) {
            $doc = $importer->importText($this->fixture($file));
            $this->assertSame('success', $doc->status, $file.': '.$doc->message);
        }
    }

    public function test_import_persists_units_invoices_statements_and_transfers(): void
    {
        $this->importAll();

        $this->assertSame(4, ConsumerUnit::count()); // geradora, 2 beneficiárias e 1 UC citada só nos demonstrativos
        $generator = ConsumerUnit::where('uc_number', '91735805198')->first();
        $this->assertTrue($generator->isGenerator());
        $this->assertSame('2496572-5', $generator->code);

        $this->assertSame(3, Invoice::count());
        $this->assertSame(3, EnergyStatement::count());
        $this->assertSame(5, CreditTransfer::count());

        // Transferências do mês corrente são abatidas do saldo disponível (fatura informa 737)
        $nov = EnergyStatement::where('reference_month', '2026-11-01')->first();
        $this->assertSame(610.0, $nov->transferred_kwh);
        $this->assertSame(737.0, $nov->balance_available_kwh);

        $invoice = Invoice::whereHas('consumerUnit', fn ($q) => $q->where('uc_number', '25953005179'))->first();
        $this->assertSame(4, $invoice->items()->whereIn('category', ['compensation', 'compensation_fee', 'consumption', 'cip'])->count());
        $this->assertEqualsWithDelta(155.40, $invoice->savings, 0.001);
    }

    public function test_reimport_is_idempotent(): void
    {
        $this->importAll();
        $this->importAll();

        $this->assertSame(3, Invoice::count());
        $this->assertSame(3, EnergyStatement::count());
        $this->assertSame(5, CreditTransfer::count());
        $this->assertSame(4, ConsumerUnit::count());
    }

    public function test_unknown_document_is_rejected_without_side_effects(): void
    {
        $doc = app(DocumentImporter::class)->importText('texto qualquer sem relação');

        $this->assertSame('failed', $doc->status);
        $this->assertSame(0, Invoice::count());
    }

    public function test_dashboard_aggregates_series_and_totals(): void
    {
        $this->importAll();
        $generator = ConsumerUnit::where('role', 'generator')->first();
        SolarGeneration::create(['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 1300]);

        $d = EnergyDashboard::make(null, '2026-08', '2026-11');

        $this->assertSame(['Ago/26', 'Set/26', 'Out/26', 'Nov/26'], $d->labels());
        $series = $d->generationSeries();
        $this->assertSame([null, null, null, 1300.0], $series['generated']);
        $this->assertSame([835.0, 766.0, 705.0, 911.0], $series['injected']);
        $this->assertSame(389.0, $series['self_consumed'][3]); // 1300 - 911

        $totals = $d->totals();
        $this->assertSame(1300.0, $totals['generated']);
        $this->assertSame(1222.0, $totals['transferred']); // 231 + 163 + 218 + 271 + 339
        $this->assertEqualsWithDelta(306.29, $totals['cost'], 0.001);
        $this->assertSame(1117.0, $totals['balance']); // 737 + 56 + 324

        // autoconsumo 1300 - 911 = 389 kWh, valorizado pela tarifa da fatura (1,30983)
        $this->assertSame(389.0, $totals['self_consumed']);
        $this->assertEqualsWithDelta(389 * 1.30983, $totals['self_savings'], 0.01);
        $this->assertSame(1257.0 + 389.0, $totals['total_consumption']); // rede (805 + 162 + 290) + autoconsumo

        $only = EnergyDashboard::make($generator->id, '2026-08', '2026-11')->totals();
        $this->assertEqualsWithDelta(110.56, $only['cost'], 0.001);
    }

    public function test_panel_pages_render(): void
    {
        $this->importAll();
        $user = User::factory()->create();
        $unit = ConsumerUnit::where('role', 'generator')->first();

        $this->actingAs($user);
        foreach ([
            '/admin', '/admin/consumer-units', "/admin/consumer-units/{$unit->id}", "/admin/consumer-units/{$unit->id}/edit",
            '/admin/solar-generations', '/admin/invoices', '/admin/invoices/'.Invoice::first()->id,
            '/admin/energy-statements', '/admin/energy-statements/'.EnergyStatement::first()->id, '/admin/imported-documents',
        ] as $url) {
            $this->get($url)->assertOk();
        }

        Livewire::test(ViewConsumerUnit::class, ['record' => $unit->getKey()])->assertOk();
    }

    public function test_solar_installments_are_part_of_cost_and_net_result(): void
    {
        $this->importAll();
        $generator = ConsumerUnit::where('role', 'generator')->first();
        // parcela de 500 de out/26 a dez/26 (3 meses) e parcela avulsa de 100 em ago/26
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'start_month' => '2026-10-01', 'end_month' => '2026-12-01', 'amount' => 500]);
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'start_month' => '2026-08-01', 'end_month' => '2026-08-01', 'amount' => 100]);

        $d = EnergyDashboard::make(null, '2026-08', '2026-11');
        $this->assertSame([100.0, 0.0, 500.0, 500.0], $d->installmentSeries());

        $t = $d->totals();
        $this->assertSame(1100.0, $t['installments']);
        $this->assertEqualsWithDelta(306.29 + 1100, $t['total_cost'], 0.001);
        $this->assertEqualsWithDelta($t['total_savings'] - 1100, $t['net_result'], 0.001);

        // parcela sem data final continua valendo
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'start_month' => '2026-11-01', 'end_month' => null, 'amount' => 10]);
        $this->assertSame(510.0, EnergyDashboard::make(null, '2026-08', '2026-11')->installmentSeries()[3]);
    }

    public function test_beneficiaries_share_solar_cost_from_previous_month_transfers(): void
    {
        $this->importAll();
        $generator = ConsumerUnit::where('role', 'generator')->first();
        $a = ConsumerUnit::where('uc_number', '25953005179')->first();
        $other = ConsumerUnit::where('uc_number', '31085605152')->first();
        $other->update(['bills_solar_cost' => false]); // fora do rateio (como Campo Grande)

        // set/26: parcela 450 ÷ geração 1000 kWh = R$ 0,45/kWh
        SolarGeneration::create(['consumer_unit_id' => $generator->id, 'reference_month' => '2026-09-01', 'generated_kwh' => 1000]);
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'start_month' => '2026-08-01', 'end_month' => '2026-12-01', 'amount' => 450]);

        $d = EnergyDashboard::make(null, '2026-08', '2026-11');
        $this->assertEqualsWithDelta(0.45, $d->solarRates()['2026-09'], 0.0001);

        // transferência de set/26 cobrada em out/26: 218 kWh × 0,45 = 98,10 (só a UC 259...; a outra está fora do rateio)
        $charges = $d->shareCharges();
        $this->assertCount(1, $charges);
        $this->assertSame('2026-10', $charges[0]['month']);
        $this->assertSame($a->id, $charges[0]['target']);
        $this->assertSame(98.10, $charges[0]['amount']);

        $this->assertSame([450.0, 450.0, 450.0, 450.0], $d->grossInstallmentSeries());
        $this->assertSame([0.0, 0.0, 98.10, 0.0], $d->abatedSeries());
        // visão geral: o rateio só redistribui, o custo total das placas não muda
        $this->assertSame([450.0, 450.0, 450.0, 450.0], $d->installmentSeries());

        // geradora: parcela abatida do rateio; beneficiária: paga o rateio
        $this->assertSame([450.0, 450.0, 351.90, 450.0], EnergyDashboard::make($generator->id, '2026-08', '2026-11')->installmentSeries());
        $this->assertSame([0.0, 0.0, 98.10, 0.0], EnergyDashboard::make($a->id, '2026-08', '2026-11')->installmentSeries());
    }
}
