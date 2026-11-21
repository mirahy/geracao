<?php

namespace App\Services\Dashboard;

use App\Models\ConsumerUnit;
use App\Models\CreditTransfer;
use App\Models\EnergyHistory;
use App\Models\EnergyStatement;
use App\Models\Invoice;
use App\Models\SolarGeneration;
use App\Models\SolarInstallment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Consolida geração, transferências, consumo e custos por mês e por unidade consumidora
 * para os dashboards. Meses são identificados como "Y-m".
 */
class EnergyDashboard
{
    private ?Collection $units = null;
    private ?Collection $invoices = null;
    private ?Collection $histories = null;
    private ?Collection $statements = null;
    private ?Collection $transfers = null;
    private ?Collection $generations = null;
    private ?Collection $installments = null;

    /**
     * @param  int|null  $unitId  limita a uma UC (null = todas)
     * @param  string|null  $from  mês inicial "Y-m"
     * @param  string|null  $to  mês final "Y-m"
     */
    public function __construct(
        private readonly ?int $unitId = null,
        private readonly ?string $from = null,
        private readonly ?string $to = null,
    ) {}

    public static function make(?int $unitId = null, ?string $from = null, ?string $to = null): self
    {
        return new self($unitId ?: null, $from ?: null, $to ?: null);
    }

    /** Meses (Y-m => rótulo) em que há qualquer dado, respeitando o período filtrado. */
    public static function availableMonths(): array
    {
        $keys = collect()
            ->merge(Invoice::pluck('reference_month'))
            ->merge(EnergyHistory::pluck('reference_month'))
            ->merge(EnergyStatement::pluck('reference_month'))
            ->merge(SolarGeneration::pluck('reference_month'))
            ->map(fn ($d) => $d->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        return $keys->mapWithKeys(fn ($k) => [$k => self::label($k)])->all();
    }

    public static function label(string $month): string
    {
        return ucfirst(CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->translatedFormat('M/y'));
    }

    /** @return list<string> */
    public function months(): array
    {
        $all = array_keys(self::availableMonths());
        if (! $all) {
            return [];
        }

        $from = $this->from ?? max($all[0], CarbonImmutable::createFromFormat('Y-m-d', end($all).'-01')->subMonths(11)->format('Y-m'));
        $to = $this->to ?? end($all);

        $months = [];
        $cursor = CarbonImmutable::createFromFormat('Y-m-d', $from.'-01');
        $last = CarbonImmutable::createFromFormat('Y-m-d', $to.'-01');
        while ($cursor <= $last && count($months) < 60) {
            $months[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $months;
    }

    /** @return list<string> rótulos dos meses */
    public function labels(): array
    {
        return array_map(fn ($m) => self::label($m), $this->months());
    }

    // ---------------------------------------------------------------- séries

    /**
     * Geração das placas (manual), energia injetada na rede e autoconsumo.
     *
     * @return array{generated: list<?float>, injected: list<?float>, self_consumed: list<?float>, grid_consumption: list<?float>}
     */
    public function generationSeries(): array
    {
        $generatorIds = $this->units()->where('role', ConsumerUnit::ROLE_GENERATOR)->pluck('id');

        $generated = $this->generations()->whereIn('consumer_unit_id', $generatorIds)
            ->groupBy(fn ($g) => $g->reference_month->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('generated_kwh'));

        $injected = $this->histories()->whereIn('consumer_unit_id', $generatorIds)
            ->groupBy(fn ($h) => $h->reference_month->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('injected_kwh'));

        $grid = $this->histories()->whereIn('consumer_unit_id', $generatorIds)
            ->groupBy(fn ($h) => $h->reference_month->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('measured_kwh'));

        $result = ['generated' => [], 'injected' => [], 'self_consumed' => [], 'grid_consumption' => []];
        foreach ($this->months() as $month) {
            $g = $generated[$month] ?? null;
            $i = $injected[$month] ?? null;
            $result['generated'][] = $g;
            $result['injected'][] = $i;
            $result['self_consumed'][] = ($g !== null && $i !== null) ? max(round($g - $i, 2), 0) : null;
            $result['grid_consumption'][] = $grid[$month] ?? null;
        }

        return $result;
    }

    /** Tarifa (R$/kWh) do mês: da fatura da UC geradora; senão a última conhecida; senão o padrão. */
    private function tariffFor(string $month): float
    {
        $invoices = $this->invoices()->filter(fn ($i) => $i->tariff_per_kwh)->sortBy('reference_month');
        $exact = $invoices->first(fn ($i) => $i->reference_month->format('Y-m') === $month);

        return (float) ($exact?->tariff_per_kwh ?? $invoices->last()?->tariff_per_kwh ?? config('geracao.default_tariff'));
    }

    /**
     * Autoconsumo (geração consumida direto da placa, sem passar pela rede) valorizado pela tarifa.
     *
     * @return array{kwh: list<?float>, amount: list<float>}
     */
    public function selfConsumptionSeries(): array
    {
        $self = $this->generationSeries()['self_consumed'];
        $amount = [];
        foreach ($this->months() as $i => $month) {
            $amount[] = round(($self[$i] ?? 0) * $this->tariffFor($month), 2);
        }

        return ['kwh' => $self, 'amount' => $amount];
    }

    /**
     * Consumo da rede (kWh) por UC e mês. Usa o histórico do demonstrativo (UC geradora)
     * e, na falta dele, o consumo faturado.
     *
     * @return array<string, list<?float>> nome da UC => valores por mês
     */
    public function consumptionSeries(): array
    {
        $months = $this->months();
        $series = [];

        foreach ($this->units() as $unit) {
            $values = [];
            $hasAny = false;
            foreach ($months as $month) {
                $history = $this->histories()->first(fn ($h) => $h->consumer_unit_id === $unit->id && $h->reference_month->format('Y-m') === $month);
                $invoice = $this->invoices()->first(fn ($i) => $i->consumer_unit_id === $unit->id && $i->reference_month->format('Y-m') === $month);
                $value = $history?->measured_kwh ?? $invoice?->consumption_kwh;
                $values[] = $value !== null ? (float) $value : null;
                $hasAny = $hasAny || $value !== null;
            }
            if ($hasAny) {
                $series[$unit->name] = $values;
            }
        }

        return $series;
    }

    /**
     * Créditos transferidos (kWh) por UC de destino e mês.
     *
     * @return array<string, list<float>>
     */
    public function transfersSeries(): array
    {
        $months = $this->months();
        $series = [];

        foreach ($this->transfers()->groupBy('target_unit_id') as $rows) {
            $name = $rows->first()->target->name;
            $byMonth = $rows->groupBy(fn ($t) => $t->reference_month->format('Y-m'))->map(fn ($r) => (float) $r->sum('kwh'));
            $series[$name] = array_map(fn ($m) => $byMonth[$m] ?? 0.0, $months);
        }

        return $series;
    }

    /**
     * Custo da fatura (R$) por UC e mês.
     *
     * @return array<string, list<float>>
     */
    public function costSeries(): array
    {
        $months = $this->months();
        $series = [];

        foreach ($this->invoices()->groupBy('consumer_unit_id') as $rows) {
            $name = $rows->first()->consumerUnit->name;
            $byMonth = $rows->groupBy(fn ($i) => $i->reference_month->format('Y-m'))->map(fn ($r) => (float) $r->sum('total_amount'));
            $series[$name] = array_map(fn ($m) => $byMonth[$m] ?? 0.0, $months);
        }

        return $series;
    }

    /**
     * Composição do custo (R$) no período: distribuição, energia, transmissão, encargos, impostos, outros.
     *
     * @return array<string, float>
     */
    public function costComposition(): array
    {
        $invoices = $this->invoices();

        return [
            'Distribuição' => (float) $invoices->sum('cost_distribution'),
            'Energia' => (float) $invoices->sum('cost_energy'),
            'Transmissão' => (float) $invoices->sum('cost_transmission'),
            'Encargos' => (float) $invoices->sum('cost_charges'),
            'Impostos' => (float) $invoices->sum('cost_taxes'),
            'Outros' => (float) $invoices->sum('cost_other'),
        ];
    }

    /**
     * Parcela das placas (R$) paga em cada mês do período.
     *
     * @return list<float>
     */
    public function installmentSeries(): array
    {
        return array_map(
            fn ($month) => round($this->installments()->filter(fn ($i) => $i->coversMonth($month))->sum('amount'), 2),
            $this->months()
        );
    }

    /** Custo mensal total (faturas + parcela das placas) x custo estimado sem solar. @return array{with: list<float>, without: list<float>} */
    public function savingsSeries(): array
    {
        $with = $without = [];
        $installments = $this->installmentSeries();
        foreach ($this->months() as $idx => $month) {
            $rows = $this->invoices()->filter(fn ($i) => $i->reference_month->format('Y-m') === $month);
            $with[] = round($rows->sum('total_amount') + $installments[$idx], 2); // fatura + parcela das placas
            $without[] = round($rows->sum(fn ($i) => $i->amount_without_solar), 2);
        }

        return ['with' => $with, 'without' => $without];
    }

    // ---------------------------------------------------------------- totais

    /** @return array<string, float|null> */
    public function totals(): array
    {
        $gen = $this->generationSeries();
        $generated = array_sum(array_filter($gen['generated'], fn ($v) => $v !== null));
        // Saldo de créditos: último saldo informado na fatura de cada UC
        $latestBalance = $this->units()->sum(
            fn ($unit) => $this->invoices()->where('consumer_unit_id', $unit->id)->sortByDesc('reference_month')->first()?->credit_balance_kwh ?? 0
        );

        $selfKwh = (float) array_sum(array_filter($gen['self_consumed'], fn ($v) => $v !== null));
        $selfAmount = (float) array_sum($this->selfConsumptionSeries()['amount']);
        $gridConsumption = (float) array_sum(array_map(fn ($v) => array_sum(array_filter($v, fn ($x) => $x !== null)), $this->consumptionSeries()));
        $compSavings = (float) $this->invoices()->sum(fn ($i) => $i->savings);

        $installments = round(array_sum($this->installmentSeries()), 2);
        $totalSavings = round($compSavings + $selfAmount, 2);

        return [
            'installments' => $installments,
            'total_cost' => round((float) $this->invoices()->sum('total_amount') + $installments, 2),
            'net_result' => round($totalSavings - $installments, 2),
            'self_consumed' => $selfKwh,
            'self_savings' => round($selfAmount, 2),
            'total_consumption' => $gridConsumption + $selfKwh,
            'total_savings' => $totalSavings,
            'generated' => (float) $generated,
            'injected' => (float) array_sum(array_filter($gen['injected'], fn ($v) => $v !== null)),
            'transferred' => (float) $this->transfers()->sum('kwh'),
            'consumption' => (float) array_sum(array_map(fn ($v) => array_sum(array_filter($v, fn ($x) => $x !== null)), $this->consumptionSeries())),
            'cost' => (float) $this->invoices()->sum('total_amount'),
            'savings' => (float) $this->invoices()->sum(fn ($i) => $i->savings),
            'compensated' => (float) $this->invoices()->sum('compensated_kwh'),
            'balance' => (float) $latestBalance,
        ];
    }

    /**
     * Resumo por UC no período.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unitsSummary(): Collection
    {
        return $this->units()->map(function (ConsumerUnit $unit) {
            $invoices = $this->invoices()->where('consumer_unit_id', $unit->id);
            $histories = $this->histories()->where('consumer_unit_id', $unit->id);
            $consumption = $histories->isNotEmpty() ? $histories->sum('measured_kwh') : $invoices->sum('consumption_kwh');
            $latestInvoice = $invoices->sortByDesc('reference_month')->first();

            $generated = (float) $this->generations()->where('consumer_unit_id', $unit->id)->sum('generated_kwh');
            $injected = (float) $histories->sum('injected_kwh');
            // autoconsumo só conta nos meses com geração informada
            $self = $this->generations()->where('consumer_unit_id', $unit->id)->sum(function ($g) use ($histories) {
                $h = $histories->first(fn ($x) => $x->reference_month->format('Y-m') === $g->reference_month->format('Y-m'));

                return $h ? max($g->generated_kwh - $h->injected_kwh, 0) : 0;
            });

            return [
                'unit' => $unit,
                'installments' => round($this->installments()->where('consumer_unit_id', $unit->id)
                    ->sum(fn ($i) => $i->amount * collect($this->months())->filter(fn ($m) => $i->coversMonth($m))->count()), 2),
                'self_consumed' => (float) $self,
                'total_consumption' => (float) $consumption + (float) $self,
                'generated' => (float) $this->generations()->where('consumer_unit_id', $unit->id)->sum('generated_kwh'),
                'consumption' => (float) $consumption,
                'compensated' => (float) $invoices->sum('compensated_kwh'),
                'sent' => (float) $this->transfers()->where('source_unit_id', $unit->id)->sum('kwh'),
                'received' => (float) $this->transfers()->where('target_unit_id', $unit->id)->sum('kwh'),
                'cost' => (float) $invoices->sum('total_amount'),
                'savings' => (float) $invoices->sum(fn ($i) => $i->savings),
                'balance' => $latestInvoice?->credit_balance_kwh,
            ];
        })->values();
    }

    // ---------------------------------------------------------------- dados

    /** @return Collection<int, ConsumerUnit> */
    public function units(): Collection
    {
        return $this->units ??= ConsumerUnit::query()
            ->when($this->unitId, fn ($q) => $q->where('id', $this->unitId))
            ->orderBy('role')->orderBy('name')->get();
    }

    private function inRange($query, string $column = 'reference_month')
    {
        $months = $this->months();
        if (! $months) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where($column, '>=', $months[0].'-01')->where($column, '<=', end($months).'-01');
    }

    private function installments(): Collection
    {
        return $this->installments ??= SolarInstallment::query()
            ->when($this->unitId, fn ($q) => $q->where('consumer_unit_id', $this->unitId))->get();
    }

    private function invoices(): Collection
    {
        return $this->invoices ??= $this->inRange(Invoice::query()->with('consumerUnit')
            ->when($this->unitId, fn ($q) => $q->where('consumer_unit_id', $this->unitId)))->get();
    }

    private function histories(): Collection
    {
        return $this->histories ??= $this->inRange(EnergyHistory::query()
            ->when($this->unitId, fn ($q) => $q->where('consumer_unit_id', $this->unitId)))->get();
    }

    private function statements(): Collection
    {
        return $this->statements ??= $this->inRange(EnergyStatement::query()
            ->when($this->unitId, fn ($q) => $q->where('consumer_unit_id', $this->unitId)))->get();
    }

    private function generations(): Collection
    {
        return $this->generations ??= $this->inRange(SolarGeneration::query()
            ->when($this->unitId, fn ($q) => $q->where('consumer_unit_id', $this->unitId)))->get();
    }

    private function transfers(): Collection
    {
        return $this->transfers ??= $this->inRange(CreditTransfer::query()->with('target')
            ->when($this->unitId, fn ($q) => $q->where(fn ($w) => $w->where('source_unit_id', $this->unitId)->orWhere('target_unit_id', $this->unitId))))->get();
    }
}
