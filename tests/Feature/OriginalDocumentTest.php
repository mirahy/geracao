<?php

namespace Tests\Feature;

use App\Filament\Resources\EnergyStatements\Pages\ListEnergyStatements;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Models\ConsumerUnit;
use App\Models\EnergyStatement;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use App\Services\Documents\PdfTextExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class OriginalDocumentTest extends TestCase
{
    use RefreshDatabase;

    private function importPdf(string $fixture, string $pdfBody): void
    {
        $this->app->bind(PdfTextExtractor::class, fn () => new class($fixture) extends PdfTextExtractor
        {
            public function __construct(private string $fixture) {}

            public function extract(string $pdfPath): string
            {
                return file_get_contents(__DIR__.'/../Fixtures/'.$this->fixture);
            }
        });

        $tmp = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($tmp, $pdfBody);
        $doc = app(DocumentImporter::class)->import($tmp, 'original.pdf');
        $this->assertSame('success', $doc->status, $doc->message);
    }

    public function test_original_pdf_is_stored_and_served_to_authenticated_users_only(): void
    {
        Storage::fake('local');
        $this->importPdf('fatura_geradora_2026_11.txt', '%PDF-1.4 conteudo da fatura');

        $invoice = Invoice::with('document')->first();
        $this->assertTrue($invoice->document->hasFile());
        $url = route('filament.admin.documents.file', $invoice->document);

        $this->get($url)->assertRedirect(); // sem login

        $this->actingAs(User::factory()->administrator()->create());
        $response = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame('%PDF-1.4 conteudo da fatura', $response->streamedContent());
    }

    public function test_view_original_action_is_available_on_invoices_and_statements(): void
    {
        Storage::fake('local');
        $this->importPdf('fatura_geradora_2026_11.txt', '%PDF-1.4 a');
        $this->importPdf('demonstrativo_2026_11.txt', '%PDF-1.4 b');
        $this->actingAs(User::factory()->administrator()->create());

        Livewire::test(ListInvoices::class)
            ->assertTableActionVisible('verOriginal', Invoice::first());

        Livewire::test(ListEnergyStatements::class)
            ->assertTableActionVisible('verOriginal', EnergyStatement::first());
    }

    public function test_statements_can_be_filtered_by_unit(): void
    {
        Storage::fake('local');
        $this->importPdf('demonstrativo_2026_11.txt', '%PDF-1.4 b');
        $this->importPdf('demonstrativo_beneficiaria_2026_11.txt', '%PDF-1.4 c');
        $this->actingAs(User::factory()->administrator()->create());

        $beneficiary = ConsumerUnit::where('uc_number', '166533905167')->first();
        $statements = EnergyStatement::all();
        $this->assertCount(2, $statements);

        Livewire::test(ListEnergyStatements::class)
            ->assertCanSeeTableRecords($statements)
            ->filterTable('consumer_unit_id', $beneficiary->id)
            ->assertCanSeeTableRecords($statements->where('consumer_unit_id', $beneficiary->id))
            ->assertCanNotSeeTableRecords($statements->where('consumer_unit_id', '!=', $beneficiary->id));
    }
}
