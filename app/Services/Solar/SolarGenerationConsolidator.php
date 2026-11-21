<?php

namespace App\Services\Solar;

use App\Models\ConsumerUnit;
use App\Models\Invoice;
use App\Models\SolarDailyProduction;
use App\Models\SolarGeneration;
use Illuminate\Support\Carbon;

/**
 * Soma a produção diária de cada período de leitura da fatura da unidade geradora
 * (dias após a leitura anterior até a leitura atual, inclusive) e grava como a geração
 * do mês de referência da fatura. Lançamentos manuais nunca são sobrescritos.
 */
class SolarGenerationConsolidator
{
    /**
     * @return array{updated: int, incomplete: array<int, string>, kept_manual: array<int, string>}
     */
    public function consolidate(ConsumerUnit $unit): array
    {
        $result = ['updated' => 0, 'incomplete' => [], 'kept_manual' => []];

        $invoices = $unit->invoices()
            ->whereNotNull('reading_previous_date')
            ->whereNotNull('reading_current_date')
            ->orderBy('reference_month')
            ->get();

        foreach ($invoices as $invoice) {
            $this->consolidateInvoice($unit, $invoice, $result);
        }

        return $result;
    }

    /** @param array{updated: int, incomplete: array<int, string>, kept_manual: array<int, string>} $result */
    private function consolidateInvoice(ConsumerUnit $unit, Invoice $invoice, array &$result): void
    {
        $start = Carbon::parse($invoice->reading_previous_date)->addDay()->startOfDay();
        $end = Carbon::parse($invoice->reading_current_date)->startOfDay();
        $label = Carbon::parse($invoice->reference_month)->translatedFormat('m/Y');

        $days = SolarDailyProduction::where('consumer_unit_id', $unit->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get();

        if ($days->isEmpty()) {
            return;
        }

        $expected = (int) $start->diffInDays($end) + 1;
        if ($days->count() < $expected) {
            $result['incomplete'][] = "{$label} ({$days->count()} de {$expected} dias)";

            return;
        }

        $month = Carbon::parse($invoice->reference_month)->startOfMonth()->toDateString();
        $existing = SolarGeneration::where('consumer_unit_id', $unit->id)->whereDate('reference_month', $month)->first();

        if ($existing && $existing->source === 'manual') {
            $result['kept_manual'][] = $label;

            return;
        }

        SolarGeneration::updateOrCreate(
            ['consumer_unit_id' => $unit->id, 'reference_month' => $month],
            [
                'generated_kwh' => round($days->sum('generated_kwh'), 2),
                'source' => 'planilha',
                'notes' => 'Soma da produção diária de '.$start->format('d/m/Y').' a '.$end->format('d/m/Y').' (período da fatura).',
            ],
        );
        $result['updated']++;
    }
}
