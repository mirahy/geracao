<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Widgets\ChartWidget;

class CostCompositionChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 7;

    protected ?string $heading = 'Composição do custo (R$)';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $composition = array_filter($this->energy()->costComposition(), fn ($v) => $v > 0);

        return [
            'labels' => array_keys($composition),
            'datasets' => [[
                'data' => array_map(fn ($v) => round($v, 2), array_values($composition)),
                'backgroundColor' => array_map(fn ($i) => ChartPalette::color($i), range(0, max(count($composition) - 1, 0))),
            ]],
        ];
    }
}
