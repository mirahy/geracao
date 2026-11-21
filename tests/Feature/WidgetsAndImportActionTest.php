<?php

namespace Tests\Feature;

use App\Filament\Pages\Dashboard;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\SolarGenerations\Pages\ManageSolarGenerations;
use App\Filament\Widgets\ConsumptionChart;
use App\Filament\Widgets\CostChart;
use App\Filament\Widgets\CostCompositionChart;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Filament\Widgets\GenerationChart;
use App\Filament\Widgets\SavingsChart;
use App\Filament\Widgets\TransfersChart;
use App\Filament\Widgets\UnitsSummaryTable;
use App\Models\ConsumerUnit;
use App\Models\Invoice;
use App\Models\SolarGeneration;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class WidgetsAndImportActionTest extends TestCase
{
    use RefreshDatabase;

    private function importFixtures(): void
    {
        $importer = app(DocumentImporter::class);
        foreach (['demonstrativo_2026_11.txt', 'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt'] as $f) {
            $importer->importText(file_get_contents(__DIR__.'/../Fixtures/'.$f));
        }
    }

    public function test_every_widget_renders_with_and_without_filters(): void
    {
        $this->importFixtures();
        $this->actingAs(User::factory()->create());
        $generator = ConsumerUnit::where('role', 'generator')->first();
        SolarGeneration::create(['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 1200]);

        $widgets = [
            EnergyStatsOverview::class, GenerationChart::class, ConsumptionChart::class, TransfersChart::class,
            CostChart::class, SavingsChart::class, CostCompositionChart::class, UnitsSummaryTable::class,
        ];

        foreach ($widgets as $widget) {
            Livewire::test($widget)->assertOk();
            Livewire::test($widget, ['pageFilters' => ['unit_id' => $generator->id, 'from' => '2026-09', 'to' => '2026-11']])->assertOk();
            Livewire::test($widget, ['record' => $generator])->assertOk();
        }
    }

    public function test_dashboard_page_with_filters(): void
    {
        $this->importFixtures();
        $this->actingAs(User::factory()->create());

        Livewire::test(Dashboard::class)
            ->fillForm(['unit_id' => ConsumerUnit::first()->id, 'from' => '2026-09', 'to' => '2026-11'])
            ->assertOk();
    }

    public function test_dashboard_renders_without_any_data(): void
    {
        $this->actingAs(User::factory()->create());

        $this->get('/admin')->assertOk();
        foreach ([EnergyStatsOverview::class, GenerationChart::class, CostChart::class, UnitsSummaryTable::class] as $widget) {
            Livewire::test($widget)->assertOk();
        }
    }

    public function test_import_action_processes_uploaded_pdfs(): void
    {
        $this->actingAs(User::factory()->create());
        Storage::fake('local');

        // extrator falso: devolve o texto de fixture em vez de chamar o pdftotext
        $this->app->bind(PdfTextExtractor::class, fn () => new class extends PdfTextExtractor
        {
            public function extract(string $pdfPath): string
            {
                return file_get_contents(__DIR__.'/../Fixtures/fatura_geradora_2026_11.txt');
            }
        });

        Livewire::test(ListInvoices::class)
            ->callAction('importarPdfs', ['files' => [UploadedFile::fake()->create('fatura.pdf', 10, 'application/pdf')]])
            ->assertNotified();

        $this->assertSame(1, Invoice::count());
    }

    public function test_solar_generation_can_be_created_and_is_unique_per_month(): void
    {
        $this->importFixtures();
        $this->actingAs(User::factory()->create());
        $generator = ConsumerUnit::where('role', 'generator')->first();

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('create', ['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 1234.5])
            ->assertHasNoActionErrors();

        $this->assertSame(1234.5, SolarGeneration::first()->generated_kwh);

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('create', ['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 10])
            ->assertHasActionErrors(['reference_month']);
    }
}
