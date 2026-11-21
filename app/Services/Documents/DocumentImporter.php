<?php

namespace App\Services\Documents;

use App\Models\ConsumerUnit;
use App\Models\CreditTransfer;
use App\Models\EnergyHistory;
use App\Models\EnergyStatement;
use App\Models\ImportedDocument;
use App\Models\Invoice;
use App\Services\Solar\SolarGenerationConsolidator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lê um PDF (fatura ou demonstrativo de compensação), extrai os dados e grava no banco.
 */
class DocumentImporter
{
    public function __construct(private readonly PdfTextExtractor $extractor) {}

    /**
     * @param  string  $path  caminho local do PDF
     * @param  string|null  $originalName  nome original do arquivo enviado
     */
    public function import(string $path, ?string $originalName = null): ImportedDocument
    {
        $hash = hash_file('sha256', $path);
        $name = $originalName ?: basename($path);

        $existing = ImportedDocument::where('hash', $hash)->first();
        if ($existing && $existing->status === 'success') {
            $existing->message = 'Arquivo já importado anteriormente (ignorado).';
            $existing->wasDuplicate = true;
            if (! $existing->path || ! Storage::disk('local')->exists($existing->path)) {
                $existing->update(['path' => $this->storeOriginal($path, $hash)]);
            }

            return $existing;
        }

        $document = $existing ?? new ImportedDocument(['hash' => $hash]);
        $document->fill([
            'original_name' => $name,
            'path' => $this->storeOriginal($path, $hash),
        ]);

        try {
            $text = $this->extractor->extract($path);

            return $this->importText($text, $document);
        } catch (Throwable $e) {
            $document->fill(['status' => 'failed', 'message' => $e->getMessage()])->save();

            return $document;
        }
    }

    /** Guarda uma cópia do PDF original (nomeada pelo hash) para visualização posterior. */
    private function storeOriginal(string $path, string $hash): string
    {
        $target = "documents/{$hash}.pdf";
        $disk = Storage::disk('local');
        if (! $disk->exists($target)) {
            $disk->put($target, file_get_contents($path));
        }

        return $target;
    }

    /** Importa a partir do texto já extraído (útil em testes). */
    public function importText(string $text, ?ImportedDocument $document = null): ImportedDocument
    {
        $document ??= ImportedDocument::firstOrNew(
            ['hash' => hash('sha256', $text)],
            ['original_name' => 'texto']
        );

        try {
            return DB::transaction(function () use ($text, $document) {
                if (InvoiceParser::supports($text)) {
                    $data = $this->normalizeDates((new InvoiceParser)->parse($text));
                    $unit = $this->saveInvoice($data, $document);
                    $type = ImportedDocument::TYPE_INVOICE;
                } elseif (StatementParser::supports($text)) {
                    $data = $this->normalizeDates((new StatementParser)->parse($text));
                    $unit = $this->saveStatement($data, $document);
                    $type = ImportedDocument::TYPE_STATEMENT;
                } else {
                    throw new \RuntimeException('Tipo de documento não reconhecido (esperado: fatura DANF3E ou demonstrativo de compensação da Energisa).');
                }

                $document->fill([
                    'type' => $type,
                    'status' => 'success',
                    'message' => $type === ImportedDocument::TYPE_INVOICE
                        ? 'Fatura importada.'
                        : 'Demonstrativo importado.',
                    'consumer_unit_id' => $unit->id,
                    'reference_month' => $data['reference_month'],
                    'parsed' => $this->summary($data),
                ])->save();

                return $document;
            });
        } catch (Throwable $e) {
            $document->fill(['status' => 'failed', 'message' => $e->getMessage()])->save();

            return $document;
        }
    }

    private function saveInvoice(array $data, ImportedDocument $document): ConsumerUnit
    {
        $unit = $this->resolveUnit($data['uc_number'], [
            'code' => $data['code'] ?? null,
            'holder_name' => $data['holder_name'] ?? null,
            'address' => $data['address'] ?? null,
            'neighborhood' => $data['neighborhood'] ?? null,
            'city' => $data['city'] ?? null,
            'classification' => $data['classification'] ?? null,
            'meter_number' => $data['meter_number'] ?? null,
        ]);

        if (($data['injected_kwh'] ?? 0) > 0 && ! $unit->isGenerator()) {
            $unit->update(['role' => ConsumerUnit::ROLE_GENERATOR]);
        }

        // Garante o ID do documento antes de relacionar
        if (! $document->exists) {
            $document->fill(['status' => 'pending'])->save();
        }

        $invoice = Invoice::updateOrCreate(
            ['consumer_unit_id' => $unit->id, 'reference_month' => $data['reference_month']],
            [
                'imported_document_id' => $document->id,
                'invoice_number' => $data['invoice_number'] ?? null,
                'series' => $data['series'] ?? null,
                'access_key' => $data['access_key'] ?? null,
                'issue_date' => $data['issue_date'] ?? null,
                'due_date' => $data['due_date'] ?? null,
                'paid_at' => $data['paid_at'] ?? null,
                'reading_previous_date' => $data['reading_previous_date'] ?? null,
                'reading_current_date' => $data['reading_current_date'] ?? null,
                'billing_days' => $data['billing_days'] ?? null,
                'meter_number' => $data['meter_number'] ?? null,
                'consumption_kwh' => $data['consumption_kwh'],
                'injected_kwh' => $data['injected_kwh'],
                'compensated_kwh' => $data['compensated_kwh'],
                'credit_balance_kwh' => $data['credit_balance_kwh'],
                'credit_expiring_kwh' => $data['credit_expiring_kwh'],
                'credit_origin' => $data['credit_origin'],
                'credit_cycle' => $data['credit_cycle'],
                'total_amount' => $data['total_amount'],
                'energy_amount' => $data['energy_amount'],
                'compensation_credit_amount' => $data['compensation_credit_amount'],
                'compensation_fee_amount' => $data['compensation_fee_amount'],
                'cip_amount' => $data['cip_amount'],
                'other_charges_amount' => $data['other_charges_amount'],
                'tariff_per_kwh' => $data['tariff_per_kwh'],
                'pis_cofins_total' => $data['pis_cofins_total'],
                'icms_base' => $data['icms_base'],
                'icms_total' => $data['icms_total'],
                'cost_distribution' => $data['cost_distribution'],
                'cost_energy' => $data['cost_energy'],
                'cost_transmission' => $data['cost_transmission'],
                'cost_charges' => $data['cost_charges'],
                'cost_taxes' => $data['cost_taxes'],
                'cost_other' => $data['cost_other'],
                'is_contingency' => $data['is_contingency'],
            ]
        );

        $invoice->items()->delete();
        foreach ($data['items'] as $item) {
            $invoice->items()->create($item);
        }

        // Se já há produção diária importada, fecha o mês com o período de leitura desta fatura.
        app(SolarGenerationConsolidator::class)->consolidate($unit);

        return $unit;
    }

    private function saveStatement(array $data, ImportedDocument $document): ConsumerUnit
    {
        $unit = $this->resolveUnit($data['uc_number'], [
            'holder_name' => $data['holder_name'] ?? null,
            'address' => $data['address'] ?? null,
        ]);

        // Demonstrativo da geradora: tem energia injetada e/ou transfere créditos.
        // Demonstrativo de beneficiária: só recebe créditos; a tabela de "transferências" lista a origem
        // (a própria geradora), então não pode virar uma transferência partindo desta UC.
        $movements = $data['movements'] ?? [];
        $isBeneficiaryStatement = ($movements['injected_kwh'] ?? 0) <= 0 && ($movements['received_kwh'] ?? 0) > 0;
        if (! $isBeneficiaryStatement && (($movements['injected_kwh'] ?? 0) > 0 || $data['transfers'] !== []) && ! $unit->isGenerator()) {
            $unit->update(['role' => ConsumerUnit::ROLE_GENERATOR]);
        }
        if ($isBeneficiaryStatement) {
            $data['transfers'] = [];
        }

        if (! $document->exists) {
            $document->fill(['status' => 'pending'])->save();
        }

        $reference = $data['reference_month'];

        // Algumas vezes o mês corrente ainda não desconta as transferências na tabela de movimentações,
        // mas elas aparecem discriminadas; o saldo disponível passa a considerá-las.
        $transfersTotal = array_sum(array_column($data['transfers'], 'kwh'));
        $transferred = (float) ($movements['transferred_kwh'] ?? 0);
        if ($transfersTotal > $transferred) {
            $movements['balance_available_kwh'] = max(0, ($movements['balance_available_kwh'] ?? 0) - ($transfersTotal - $transferred));
            $movements['transferred_kwh'] = $transfersTotal;
        }

        $statement = EnergyStatement::updateOrCreate(
            ['consumer_unit_id' => $unit->id, 'reference_month' => $reference],
            array_merge($movements, [
                'imported_document_id' => $document->id,
                'previous_balance_composition' => $data['previous_balance_composition'],
            ])
        );

        // Transferências: recria a partir do demonstrativo
        $statement->transfers()->delete();
        foreach ($data['transfers'] as $transfer) {
            $target = $this->resolveUnit($transfer['uc_number']);
            CreditTransfer::create([
                'energy_statement_id' => $statement->id,
                'source_unit_id' => $unit->id,
                'target_unit_id' => $target->id,
                'reference_month' => $reference,
                'cycle_month' => $transfer['cycle_month'],
                'percentage' => $transfer['percentage'],
                'kwh' => $transfer['kwh'],
            ]);
        }

        // Histórico mensal: o demonstrativo mais recente prevalece
        foreach ($data['history'] as $row) {
            $history = EnergyHistory::firstOrNew([
                'consumer_unit_id' => $unit->id,
                'reference_month' => $row['reference_month'],
            ]);
            if ($history->exists && $history->source_reference && $history->source_reference->toDateString() > $reference) {
                continue;
            }
            $history->fill($row)->fill(['source_reference' => $reference])->save();
        }

        return $unit;
    }

    /** Cria (ou completa) a UC identificada pelo número, sem sobrescrever dados já preenchidos. */
    private function resolveUnit(string $ucNumber, array $attributes = []): ConsumerUnit
    {
        $unit = ConsumerUnit::firstOrNew(['uc_number' => $ucNumber]);

        $attributes = array_filter($attributes, fn ($v) => $v !== null && $v !== '');
        foreach ($attributes as $key => $value) {
            if (blank($unit->{$key})) {
                $unit->{$key} = $value;
            }
        }

        if (blank($unit->name) || Str::startsWith($unit->name, 'UC ')) {
            $unit->name = $unit->neighborhood
                ? Str::title(mb_strtolower(trim(($unit->city ? $unit->city.' - ' : '').$unit->neighborhood)))
                : 'UC '.ConsumerUnit::formatUcNumber($ucNumber);
        }

        if (! $unit->exists) {
            $unit->role = ConsumerUnit::ROLE_RECEIVER;
        }

        $unit->save();

        return $unit;
    }

    /** Converte todas as datas (Carbon) em "Y-m-d", formato usado nas colunas date. */
    private function normalizeDates(array $data): array
    {
        array_walk_recursive($data, function (&$value) {
            if ($value instanceof \DateTimeInterface) {
                $value = $value->format('Y-m-d');
            }
        });

        return $data;
    }

    /** Dados resumidos guardados com o documento (sem os itens detalhados). */
    private function summary(array $data): array
    {
        return json_decode(json_encode($data), true);
    }
}
