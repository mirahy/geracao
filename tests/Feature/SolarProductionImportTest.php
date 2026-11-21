<?php

namespace Tests\Feature;

use App\Filament\Resources\SolarGenerations\Pages\ManageSolarGenerations;
use App\Models\ConsumerUnit;
use App\Models\SolarDailyProduction;
use App\Models\SolarGeneration;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\InvoiceParser;
use App\Services\Solar\DailyProductionSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

class SolarProductionImportTest extends TestCase
{
    use RefreshDatabase;

    /** Gera um .xlsx no mesmo formato do exportado pelo aplicativo do inversor (textos em sharedStrings). */
    private function makeSpreadsheet(string $from, string $to, float $kwhPerDay = 10): string
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

        return $path;
    }

    private function upload(string $from, string $to, float $kwhPerDay = 10): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('estatisticas.xlsx', file_get_contents($this->makeSpreadsheet($from, $to, $kwhPerDay)));
    }

    private function importGeneratorInvoice(): ConsumerUnit
    {
        app(DocumentImporter::class)->importText(file_get_contents(__DIR__.'/../Fixtures/fatura_geradora_2026_11.txt'));

        return ConsumerUnit::where('role', 'generator')->firstOrFail();
    }

    public function test_spreadsheet_is_read_into_daily_rows(): void
    {
        $rows = (new DailyProductionSpreadsheet)->read($this->makeSpreadsheet('2026-10-30', '2026-11-02', 12.5));

        $this->assertCount(4, $rows);
        $this->assertSame(['date' => '2026-10-30', 'kwh' => 12.5, 'revenue' => 1.5], $rows[0]);
    }

    public function test_days_are_summed_by_the_invoice_reading_period(): void
    {
        $generator = $this->importGeneratorInvoice();
        $this->actingAs(User::factory()->create());

        // A planilha vai além do período da fatura (leitura 16/10 → 16/11): só 17/10 a 16/11 entra no mês.
        $file = $this->upload('2026-10-10', '2026-11-20', 10);

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', ['consumer_unit_id' => $generator->id, 'file' => $file])
            ->assertHasNoActionErrors();

        $this->assertSame(42, SolarDailyProduction::count());
        $generation = SolarGeneration::sole();
        $this->assertSame('2026-11-01', $generation->reference_month->format('Y-m-d'));
        $this->assertSame(310.0, $generation->generated_kwh); // 31 dias × 10 kWh
        $this->assertSame('planilha', $generation->source);
    }

    public function test_incomplete_period_is_not_saved(): void
    {
        $generator = $this->importGeneratorInvoice();
        $this->actingAs(User::factory()->create());

        $file = $this->upload('2026-10-25', '2026-11-20');

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', ['consumer_unit_id' => $generator->id, 'file' => $file]);

        $this->assertSame(0, SolarGeneration::count());
    }

    public function test_incomplete_period_can_be_saved_when_allowed(): void
    {
        $generator = $this->importGeneratorInvoice();
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', [
                'consumer_unit_id' => $generator->id,
                'file' => $this->upload('2026-11-07', '2026-11-16'),
                'allow_incomplete' => true,
            ]);

        $generation = SolarGeneration::sole();
        $this->assertSame(100.0, $generation->generated_kwh); // 10 dias × 10 kWh
        $this->assertStringContainsString('Parcial: apenas 10 de 31 dias', $generation->notes);
    }

    public function test_manual_entry_is_kept_and_reimport_updates_spreadsheet_rows(): void
    {
        $generator = $this->importGeneratorInvoice();
        $this->actingAs(User::factory()->create());

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('create', ['consumer_unit_id' => $generator->id, 'reference_month' => '2026-11-01', 'generated_kwh' => 999]);
        $this->assertSame('manual', SolarGeneration::sole()->source);

        $upload = fn (float $kwh) => $this->upload('2026-10-17', '2026-11-16', $kwh);

        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', ['consumer_unit_id' => $generator->id, 'file' => $upload(10)]);
        $this->assertSame(999.0, SolarGeneration::sole()->generated_kwh);

        SolarGeneration::sole()->delete();
        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', ['consumer_unit_id' => $generator->id, 'file' => $upload(10)]);
        Livewire::test(ManageSolarGenerations::class)
            ->callAction('importarProducaoDiaria', ['consumer_unit_id' => $generator->id, 'file' => $upload(20)]);

        $this->assertSame(620.0, SolarGeneration::sole()->generated_kwh);
        $this->assertSame(31, SolarDailyProduction::count());
    }

    public function test_importing_the_invoice_after_the_spreadsheet_closes_the_month(): void
    {
        $generator = ConsumerUnit::create([
            'uc_number' => (new InvoiceParser)->parse(file_get_contents(__DIR__.'/../Fixtures/fatura_geradora_2026_11.txt'))['uc_number'],
            'name' => 'Geradora',
            'role' => 'generator',
        ]);
        foreach ((new DailyProductionSpreadsheet)->read($this->makeSpreadsheet('2026-10-17', '2026-11-16', 5)) as $row) {
            SolarDailyProduction::create(['consumer_unit_id' => $generator->id, 'date' => $row['date'], 'generated_kwh' => $row['kwh']]);
        }

        $unit = $this->importGeneratorInvoice();

        $this->assertSame(155.0, SolarGeneration::where('consumer_unit_id', $unit->id)->sole()->generated_kwh);
    }
}
