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

    private ?array $rates = null;

    private ?Collection $shareCharges = null;

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
     * Custo do kWh gerado pelas placas, por mês ("Y-m"): parcela do mês ÷ geração total do mês.
     * Calculado sobre todas as UCs, sem filtros, porque o rateio usa meses fora do período exibido.
     *
     * @return array<string, float>
     */
    public function solarRates(): array
    {
        if ($this->rates !== null) {
            return $this->rates;
        }

        $generated = SolarGeneration::all()
            ->groupBy(fn ($g) => $g->reference_month->format('Y-m'))
            ->map(fn ($rows) => (float) $rows->sum('generated_kwh'));

        $rates = [];
        foreach ($generated as $month => $kwh) {
            $amount = $this->installments()->filter(fn ($i) => $i->coversMonth($month))->sum('amount');
            if ($kwh > 0 && $amount > 0) {
                $rates[$month] = $amount / $kwh;
            }
        }

        return $this->rates = $rates;
    }

    /**
     * Rateio: no mês seguinte à transferência, cada UC beneficiária paga
     * kWh recebido × custo do kWh das placas (do mês da transferência).
     * UCs com "bills_solar_cost" desligado (ex.: Campo Grande) ficam de fora.
     *
     * @return Collection<int, array{source: int, target: int, month: string, kwh: float, rate: float, amount: float}>
     */
    public function shareCharges(): Collection
    {
        $months = $this->months();
        if (! $months) {
            return collect();
        }

        $first = CarbonImmutable::createFromFormat('Y-m-d', $months[0].'-01')->subMonth()->format('Y-m-d');
        $last = CarbonImmutable::createFromFormat('Y-m-d', end($months).'-01')->subMonth()->format('Y-m-d');

        return $this->shareCharges ??= CreditTransfer::with('target')
            ->whereBetween('reference_month', [$first, $last])
            ->get()
            ->filter(fn ($t) => $t->target->bills_solar_cost && $t->source_unit_id !== $t->target_unit_id)
            ->map(function ($t) {
                $rate = $this->solarRates()[$t->reference_month->format('Y-m')] ?? null;

                return $rate === null ? null : [
                    'source' => $t->source_unit_id,
                    'target' => $t->target_unit_id,
                    'month' => $t->reference_month->addMonth()->format('Y-m'),
                    'kwh' => $t->kwh,
                    'rate' => $rate,
                    'amount' => round($t->kwh * $rate, 2),
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * Custo do kWh de uma UC no período filtrado.
     *
     * Geradora: (parcela após rateio + faturas da própria UC + faturas das beneficiárias marcadas
     * para entrar no cálculo) ÷ geração das placas.
     * Beneficiária: (rateio cobrado + faturas) ÷ consumo em kWh das faturas.
     *
     * @return array{rate: ?float, amount: float, kwh: float, parts: array<string, float>, per: string}
     */
    public function costPerKwh(ConsumerUnit $unit): array
    {
        $ids = [$unit->id];

        if ($unit->role === ConsumerUnit::ROLE_GENERATOR) {
            $included = ConsumerUnit::where('role', ConsumerUnit::ROLE_RECEIVER)->where('counts_in_generator_cost', true)->get();
            $ids = [...$ids, ...$included->pluck('id')->all()];
        }

        $invoices = $this->inRange(Invoice::query()->whereIn('consumer_unit_id', $ids))->get();
        $own = (float) $invoices->where('consumer_unit_id', $unit->id)->sum('total_amount');

        if ($unit->role === ConsumerUnit::ROLE_GENERATOR) {
            $parts = ['Parcela das placas (após rateio)' => $this->installmentFor($unit->id), 'Faturas da unidade' => $own];
            foreach ($included as $receiver) {
                $parts['Fatura: '.$receiver->name] = (float) $invoices->where('consumer_unit_id', $receiver->id)->sum('total_amount');
            }
            $kwh = (float) $this->inRange(SolarGeneration::query()->where('consumer_unit_id', $unit->id))->sum('generated_kwh');
            $per = 'geração das placas';
        } else {
            $parts = [
                'Rateio das placas' => (float) $this->shareCharges()->where('target', $unit->id)->sum('amount'),
                'Faturas da unidade' => $own,
            ];
            $kwh = (float) $invoices->sum('consumption_kwh');
            $per = 'consumo das faturas';
        }

        $amount = round(array_sum($parts), 2);

        return ['rate' => $kwh > 0 ? $amount / $kwh : null, 'amount' => $amount, 'kwh' => $kwh, 'parts' => $parts, 'per' => $per];
    }

    /** Custo do kWh da UC quando o filtro/registro aponta uma única unidade; senão null. */
    public function scopeCostPerKwh(): ?array
    {
        return $this->units()->count() === 1 ? $this->costPerKwh($this->units()->first()) : null;
    }

    /** @return list<float> parcela bruta paga pelas UCs geradoras no escopo, por mês */
    public function grossInstallmentSeries(): array
    {
        $ids = $this->units()->pluck('id');

        return array_map(
            fn ($month) => round($this->installments()->filter(fn ($i) => $ids->contains($i->consumer_unit_id) && $i->coversMonth($month))->sum('amount'), 2),
            $this->months()
        );
    }

    /** @return list<float> rateio abatido da parcela das UCs geradoras do escopo */
    public function abatedSeries(): array
    {
        $ids = $this->units()->pluck('id');

        return array_map(
            fn ($month) => round($this->shareCharges()->filter(fn ($c) => $c['month'] === $month && $ids->contains($c['source']))->sum('amount'), 2),
            $this->months()
        );
    }

    /** @return list<float> rateio cobrado das UCs beneficiárias do escopo */
    public function chargedSeries(): array
    {
        $ids = $this->units()->pluck('id');

        return array_map(
            fn ($month) => round($this->shareCharges()->filter(fn ($c) => $c['month'] === $month && $ids->contains($c['target']))->sum('amount'), 2),
            $this->months()
        );
    }

    /**
     * Custo das placas (R$) por mês no escopo: parcela − rateio abatido (geradora) + rateio cobrado (beneficiárias).
     *
     * @return list<float>
     */
    public function installmentSeries(): array
    {
        $gross = $this->grossInstallmentSeries();
        $abated = $this->abatedSeries();
        $charged = $this->chargedSeries();

        return array_map(fn ($i) => round($gross[$i] - $abated[$i] + $charged[$i], 2), array_keys($gross));
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
        $latestBalance = $this->units()->sum(fn ($unit) => $this->balanceFor($unit));
        $selfKwh = (float) array_sum(array_filter($gen['self_consumed'], fn ($v) => $v !== null));
        $selfAmount = (float) array_sum($this->selfConsumptionSeries()['amount']);
        $gridConsumption = (float) array_sum(array_map(fn ($v) => array_sum(array_filter($v, fn ($x) => $x !== null)), $this->consumptionSeries()));
        $compSavings = (float) $this->invoices()->sum(fn ($i) => $i->savings);

        $installments = round(array_sum($this->installmentSeries()), 2);
        $totalSavings = round($compSavings + $selfAmount, 2);

        return [
            'installments' => $installments,
            'installments_gross' => round(array_sum($this->grossInstallmentSeries()), 2),
            'share_abated' => round(array_sum($this->abatedSeries()), 2),
            'share_charged' => round(array_sum($this->chargedSeries()), 2),
            'solar_cost_per_kwh' => ($rates = $this->solarRates()) ? $rates[array_key_last($rates)] : null,
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

            $generated = (float) $this->generations()->where('consumer_unit_id', $unit->id)->sum('generated_kwh');
            $injected = (float) $histories->sum('injected_kwh');
            // autoconsumo só conta nos meses com geração informada
            $self = $this->generations()->where('consumer_unit_id', $unit->id)->sum(function ($g) use ($histories) {
                $h = $histories->first(fn ($x) => $x->reference_month->format('Y-m') === $g->reference_month->format('Y-m'));

                return $h ? max($g->generated_kwh - $h->injected_kwh, 0) : 0;
            });

            return [
                'unit' => $unit,
                'installments' => $this->installmentFor($unit->id),
                'self_consumed' => (float) $self,
                'total_consumption' => (float) $consumption + (float) $self,
                'generated' => (float) $this->generations()->where('consumer_unit_id', $unit->id)->sum('generated_kwh'),
                'consumption' => (float) $consumption,
                'compensated' => (float) $invoices->sum('compensated_kwh'),
                'sent' => (float) $this->transfers()->where('source_unit_id', $unit->id)->sum('kwh'),
                'received' => (float) $this->transfers()->where('target_unit_id', $unit->id)->sum('kwh'),
                'cost' => (float) $invoices->sum('total_amount'),
                'savings' => (float) $invoices->sum(fn ($i) => $i->savings),
                'balance' => $this->balanceFor($unit),
                'cost_per_kwh' => $this->costPerKwh($unit)['rate'],
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

    /**
     * Saldo de créditos (kWh) da UC até o último mês do período, calculado a partir dos dados importados
     * (não usa o "Saldo acumulado" impresso na fatura):
     * - beneficiária: créditos recebidos por transferência − créditos compensados nas faturas (acumulado);
     * - geradora: saldo disponível do último demonstrativo de compensação.
     */
    public function balanceFor(ConsumerUnit $unit): float
    {
        $months = $this->months();
        if (! $months) {
            return 0.0;
        }
        $end = end($months).'-01';

        if ($unit->isGenerator()) {
            $statement = EnergyStatement::where('consumer_unit_id', $unit->id)
                ->where('reference_month', '<=', $end)->orderByDesc('reference_month')->first();

            return (float) ($statement?->balance_available_kwh ?? 0);
        }

        $received = CreditTransfer::where('target_unit_id', $unit->id)->where('reference_month', '<=', $end)->sum('kwh');
        $compensated = Invoice::where('consumer_unit_id', $unit->id)->where('reference_month', '<=', $end)->sum('compensated_kwh');

        return round($received - $compensated, 2);
    }

    /** Custo das placas de uma UC no período: parcela própria − rateio abatido + rateio cobrado. */
    private function installmentFor(int $unitId): float
    {
        $months = $this->months();
        $gross = $this->installments()->where('consumer_unit_id', $unitId)
            ->sum(fn ($i) => $i->amount * collect($months)->filter(fn ($m) => $i->coversMonth($m))->count());

        return round(
            $gross
            - $this->shareCharges()->where('source', $unitId)->sum('amount')
            + $this->shareCharges()->where('target', $unitId)->sum('amount'),
            2
        );
    }

    private function installments(): Collection
    {
        // sem filtro de UC: o rateio depende das parcelas e da geração de todas as UCs geradoras
        return $this->installments ??= SolarInstallment::all();
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
