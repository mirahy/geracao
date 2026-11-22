<?php

namespace App\Services\Rateio;

use App\Models\ConsumerUnit;
use App\Models\RateioPayment;
use App\Models\Scopes\AccessibleUnitsScope;
use App\Services\Dashboard\EnergyDashboard;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Compara, mês a mês, o rateio devido por cada unidade beneficiária com o que já foi pago
 * (um mês pode ter vários pagamentos parciais).
 */
class RateioLedger
{
    public const PENDING = 'pending';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const OVERPAID = 'overpaid';

    public const WITHOUT_CHARGE = 'without_charge';

    /**
     * @param  list<int>|null  $unitIds  limita às unidades informadas (null = todas)
     * @return Collection<int, array{unit_id: int, unit: string, month: string, due: float, paid: float, balance: float, status: string, payments: int}>
     */
    public function rows(?array $unitIds = null): Collection
    {
        return AccessibleUnitsScope::withoutScope(function () use ($unitIds) {
            $dues = $this->dues();
            $payments = RateioPayment::withoutGlobalScopes()->get()
                ->groupBy(fn (RateioPayment $p) => $p->consumer_unit_id.'|'.$p->reference_month->format('Y-m'));

            $keys = $dues->keys()->merge($payments->keys())->unique();
            $names = ConsumerUnit::withoutGlobalScopes()->pluck('name', 'id');

            return $keys
                ->map(function (string $key) use ($dues, $payments, $names) {
                    [$unitId, $month] = explode('|', $key);
                    $due = round((float) ($dues[$key] ?? 0), 2);
                    $paid = round((float) ($payments[$key] ?? collect())->sum('amount'), 2);

                    return [
                        'unit_id' => (int) $unitId,
                        'unit' => $names[(int) $unitId] ?? "UC {$unitId}",
                        'month' => $month,
                        'due' => $due,
                        'paid' => $paid,
                        'balance' => round($due - $paid, 2),
                        'status' => $this->status($due, $paid),
                        'payments' => ($payments[$key] ?? collect())->count(),
                    ];
                })
                ->when($unitIds !== null, fn (Collection $rows) => $rows->whereIn('unit_id', $unitIds))
                ->sortBy([['month', 'desc'], ['unit', 'asc']])
                ->values();
        });
    }

    /** Mês mais antigo com saldo a pagar para a unidade ("Y-m"), para sugerir no lançamento. */
    public function oldestOpenMonth(int $unitId): ?string
    {
        return $this->rows([$unitId])->where('balance', '>', 0.004)->sortBy('month')->first()['month'] ?? null;
    }

    public function status(float $due, float $paid): string
    {
        if ($due <= 0.004) {
            return $paid > 0.004 ? self::WITHOUT_CHARGE : self::PAID;
        }

        return match (true) {
            $paid <= 0.004 => self::PENDING,
            $paid + 0.004 < $due => self::PARTIAL,
            $paid > $due + 0.004 => self::OVERPAID,
            default => self::PAID,
        };
    }

    /** @return Collection<string, float> "unidade|Y-m" => rateio devido */
    private function dues(): Collection
    {
        $months = array_keys(EnergyDashboard::availableMonths());
        if (! $months) {
            return collect();
        }

        // O rateio de um mês sai dos créditos transferidos no mês anterior: um demonstrativo já importado
        // gera cobrança no mês seguinte ao último com dados.
        $to = CarbonImmutable::createFromFormat('Y-m-d', end($months).'-01')->addMonth()->format('Y-m');

        return EnergyDashboard::make(null, $months[0], $to)
            ->shareCharges()
            ->groupBy(fn (array $charge) => $charge['target'].'|'.$charge['month'])
            ->map(fn (Collection $charges) => (float) $charges->sum('amount'));
    }
}
