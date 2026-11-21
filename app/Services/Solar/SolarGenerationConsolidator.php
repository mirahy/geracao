<?php

namespace App\Services\Solar;

use App\Models\ConsumerUnit;
use App\Models\SolarDailyProduction;
use App\Models\SolarGeneration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Soma a produção diária de cada período de leitura da fatura da unidade geradora
 * (dias após a leitura anterior até a leitura atual, inclusive) e grava como a geração
 * do mês de referência da fatura.
 *
 * Grava sozinho apenas o que é seguro: períodos completos sem lançamento manual divergente.
 * O restante fica "pendente" para decisão do usuário: períodos com dias faltando e
 * períodos cujo valor da planilha difere do lançado manualmente.
 *
 * @phpstan-type Period array{month: string, label: string, start: Carbon, end: Carbon, days: int, expected: int, kwh: float, complete: bool, existing: ?SolarGeneration, conflict: bool}
 */
class SolarGenerationConsolidator
{
    private const TOLERANCE = 0.01;

    /**
     * @return array{updated: int, pending: Collection<int, array>}
     */
    public function consolidate(ConsumerUnit $unit): array
    {
        $updated = 0;

        foreach ($this->periods($unit) as $period) {
            if ($this->canAutoSave($period)) {
                $this->save($unit, $period);
                $updated++;
            }
        }

        return ['updated' => $updated, 'pending' => $this->pending($unit)];
    }

    /**
     * Períodos que dependem de decisão do usuário.
     *
     * @return Collection<int, array>
     */
    public function pending(ConsumerUnit $unit): Collection
    {
        return $this->periods($unit)
            ->filter(fn (array $p) => ! $this->isSettled($p) && ! $this->canAutoSave($p))
            ->values();
    }

    /**
     * Grava os meses escolhidos (formato Y-m), sobrescrevendo o que existir.
     *
     * @param  array<int, string>  $months
     */
    public function apply(ConsumerUnit $unit, array $months): int
    {
        $count = 0;
        foreach ($this->pending($unit) as $period) {
            if (in_array(substr($period['month'], 0, 7), $months, true)) {
                $this->save($unit, $period);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Um período por fatura da unidade que tenha ao menos um dia de produção.
     *
     * @return Collection<int, array>
     */
    public function periods(ConsumerUnit $unit): Collection
    {
        return $unit->invoices()
            ->whereNotNull('reading_previous_date')
            ->whereNotNull('reading_current_date')
            ->orderBy('reference_month')
            ->get()
            ->map(function ($invoice) use ($unit) {
                $start = Carbon::parse($invoice->reading_previous_date)->addDay()->startOfDay();
                $end = Carbon::parse($invoice->reading_current_date)->startOfDay();
                $days = SolarDailyProduction::where('consumer_unit_id', $unit->id)
                    ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
                    ->get();
                $month = Carbon::parse($invoice->reference_month)->startOfMonth();
                $expected = (int) $start->diffInDays($end) + 1;
                $kwh = round($days->sum('generated_kwh'), 2);
                $existing = SolarGeneration::where('consumer_unit_id', $unit->id)->whereDate('reference_month', $month->toDateString())->first();

                return [
                    'month' => $month->toDateString(),
                    'label' => $month->format('m/Y'),
                    'start' => $start,
                    'end' => $end,
                    'days' => $days->count(),
                    'expected' => $expected,
                    'kwh' => $kwh,
                    'complete' => $days->count() >= $expected,
                    'existing' => $existing,
                    'conflict' => $existing?->source === 'manual' && abs($existing->generated_kwh - $kwh) > self::TOLERANCE,
                ];
            })
            ->filter(fn (array $p) => $p['days'] > 0)
            ->values();
    }

    /** Período completo e sem lançamento manual divergente: pode ser gravado sem perguntar. */
    private function canAutoSave(array $period): bool
    {
        return $period['complete'] && ! $period['conflict'] && $period['existing']?->source !== 'manual';
    }

    /** Já está gravado exatamente como a planilha indica (ou manual idêntico). */
    private function isSettled(array $period): bool
    {
        $existing = $period['existing'];

        return $existing !== null && abs($existing->generated_kwh - $period['kwh']) <= self::TOLERANCE;
    }

    private function save(ConsumerUnit $unit, array $period): void
    {
        if ($this->isSettled($period)) {
            return;
        }

        $note = 'Soma da produção diária de '.$period['start']->format('d/m/Y').' a '.$period['end']->format('d/m/Y').' (período da fatura)'
            .($period['complete'] ? '.' : ". Parcial: apenas {$period['days']} de {$period['expected']} dias na planilha.");

        SolarGeneration::updateOrCreate(
            ['consumer_unit_id' => $unit->id, 'reference_month' => $period['month']],
            ['generated_kwh' => $period['kwh'], 'source' => 'planilha', 'notes' => $note],
        );
    }
}
