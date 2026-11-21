<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Support\RawJs;
use Filament\Widgets\ChartWidget;

class CostChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 5;

    protected ?string $heading = 'Custo das faturas por unidade (R$)';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $e = $this->energy();
        $datasets = [];
        foreach ($e->costSeries() as $name => $values) {
            $datasets[] = ['label' => $name, 'data' => $values, 'backgroundColor' => ChartPalette::color(count($datasets))];
        }

        return ['labels' => $e->labels(), 'datasets' => $datasets];
    }

    protected function getOptions(): RawJs
    {
        return RawJs::make(<<<'JS'
        {
            scales: {
                x: { stacked: true },
                y: { stacked: true, ticks: { callback: (value) => 'R$ ' + value.toLocaleString('pt-BR') } },
            },
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
