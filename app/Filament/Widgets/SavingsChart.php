<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class SavingsChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 6;

    protected ?string $heading = 'Custo com x sem energia solar (R$)';

    protected ?string $description = 'Sem solar = fatura + economia da compensação + economia do autoconsumo (kWh × tarifa).';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $e = $this->energy();
        $s = $e->savingsSeries();
        $selfAmount = $e->selfConsumptionSeries()['amount'];
        $s['without'] = array_map(fn ($v, $i) => round($v + $selfAmount[$i], 2), $s['without'], array_keys($s['without']));

        return [
            'labels' => $e->labels(),
            'datasets' => [
                ['label' => 'Sem solar', 'data' => $s['without'], 'backgroundColor' => '#fca5a5', 'borderRadius' => 4],
                ['label' => 'Com solar (fatura + parcela)', 'data' => $s['with'], 'backgroundColor' => '#10b981', 'borderRadius' => 4],
            ],
        ];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: { y: { ticks: { callback: (value) => 'R$ ' + value.toLocaleString('pt-BR') } } },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: (ctx) => ctx.dataset.label + ': R$ ' + Number(ctx.parsed.y).toLocaleString('pt-BR', { minimumFractionDigits: 2 }),
                    },
                },
            },
        }
        JS);
    }
}
