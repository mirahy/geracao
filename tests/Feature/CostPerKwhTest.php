<?php

namespace Tests\Feature;

use App\Filament\Resources\ConsumerUnits\Pages\EditConsumerUnit;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Models\ConsumerUnit;
use App\Models\Invoice;
use App\Models\SolarGeneration;
use App\Models\SolarInstallment;
use App\Models\User;
use App\Services\Dashboard\EnergyDashboard;
use App\Services\Documents\DocumentImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CostPerKwhTest extends TestCase
{
    use RefreshDatabase;

    private function setUpData(): array
    {
        $importer = app(DocumentImporter::class);
        foreach (['demonstrativo_2026_11.txt', 'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt'] as $f) {
            $importer->importText(file_get_contents(__DIR__.'/../Fixtures/'.$f));
        }

        $generator = ConsumerUnit::where('role', 'generator')->firstOrFail();
        $receiver = ConsumerUnit::where('role', 'receiver')->whereHas('invoices')->firstOrFail();
        SolarGeneration::create(['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 1000]);
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'amount' => 500, 'start_month' => '2026-11-01', 'end_month' => '2026-11-01']);

        return [$generator, $receiver];
    }

    public function test_generator_cost_per_kwh_uses_installment_invoice_and_selected_receivers(): void
    {
        [$generator, $receiver] = $this->setUpData();
        $dashboard = fn () => EnergyDashboard::make($generator->id, '2026-11', '2026-11');

        $generatorInvoice = (float) Invoice::where('consumer_unit_id', $generator->id)->sum('total_amount');
        $receiverInvoice = (float) Invoice::where('consumer_unit_id', $receiver->id)->sum('total_amount');

        $base = $dashboard()->costPerKwh($generator);
        $this->assertSame(1000.0, $base['kwh']);
        $this->assertEqualsWithDelta((500 + $generatorInvoice) / 1000, $base['rate'], 0.0001);

        $receiver->update(['counts_in_generator_cost' => true]);

        $with = $dashboard()->costPerKwh($generator);
        $this->assertEqualsWithDelta((500 + $generatorInvoice + $receiverInvoice) / 1000, $with['rate'], 0.0001);
        $this->assertArrayHasKey('Fatura: '.$receiver->name, $with['parts']);
    }

    public function test_receiver_cost_per_kwh_uses_share_charge_and_invoice_consumption(): void
    {
        [, $receiver] = $this->setUpData();

        $result = EnergyDashboard::make($receiver->id, '2026-11', '2026-11')->costPerKwh($receiver);

        $invoice = Invoice::where('consumer_unit_id', $receiver->id)->first();
        $this->assertSame((float) $invoice->consumption_kwh, $result['kwh']);
        $this->assertEqualsWithDelta(((float) $invoice->total_amount + $result['parts']['Rateio das placas']) / $invoice->consumption_kwh, $result['rate'], 0.0001);
    }

    public function test_solar_cost_card_follows_the_period_and_is_hidden_for_receivers(): void
    {
        [$generator, $receiver] = $this->setUpData();
        $this->actingAs(User::factory()->administrator()->create());
        SolarGeneration::create(['consumer_unit_id' => $generator->id, 'reference_month' => '2026-10-01', 'generated_kwh' => 500]);
        SolarInstallment::create(['consumer_unit_id' => $generator->id, 'amount' => 100, 'start_month' => '2026-10-01', 'end_month' => '2026-10-01']);

        // Nov: 500 ÷ 1000; Out: 100 ÷ 500; período Out–Nov: 600 ÷ 1500.
        $this->assertEqualsWithDelta(0.5, EnergyDashboard::make(null, '2026-11', '2026-11')->totals()['solar_cost_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(0.2, EnergyDashboard::make(null, '2026-10', '2026-10')->totals()['solar_cost_per_kwh'], 0.0001);
        $this->assertEqualsWithDelta(0.4, EnergyDashboard::make($generator->id, '2026-10', '2026-11')->totals()['solar_cost_per_kwh'], 0.0001);

        Livewire::test(EnergyStatsOverview::class, ['pageFilters' => ['unit_id' => $generator->id]])->assertSee('Custo do kWh das placas');
        Livewire::test(EnergyStatsOverview::class, ['record' => $receiver])
            ->assertDontSee('Custo do kWh das placas')
            ->assertDontSee('Geração das placas')
            ->assertDontSee('Autoconsumo das placas')
            ->assertDontSee('Injetado na rede');
        Livewire::test(EnergyStatsOverview::class, ['record' => $generator])
            ->assertSee('Geração das placas')->assertSee('Autoconsumo das placas')->assertSee('Injetado na rede');
    }

    public function test_rate_is_null_without_kwh(): void
    {
        [$generator] = $this->setUpData();

        $this->assertNull(EnergyDashboard::make($generator->id, '2026-01', '2026-01')->costPerKwh($generator)['rate']);
    }

    public function test_flag_is_only_offered_for_receivers_and_saved(): void
    {
        [$generator, $receiver] = $this->setUpData();
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(EditConsumerUnit::class, ['record' => $generator->getRouteKey()])
            ->assertFormFieldHidden('counts_in_generator_cost');

        Livewire::test(EditConsumerUnit::class, ['record' => $receiver->getRouteKey()])
            ->assertFormFieldVisible('counts_in_generator_cost')
            ->fillForm(['counts_in_generator_cost' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($receiver->fresh()->counts_in_generator_cost);
    }
}
