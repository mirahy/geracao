<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Widgets\ChartWidget;

class ConsumptionChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 3;

    protected int | string | array $columnSpan = 'full';

    protected ?string $heading = 'Consumo por unidade consumidora (kWh)';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $e = $this->energy();
        $datasets = [];
        foreach ($e->consumptionSeries() as $name => $values) {
            $color = ChartPalette::color(count($datasets));
            $datasets[] = ['label' => $name, 'data' => $values, 'borderColor' => $color, 'backgroundColor' => $color, 'tension' => 0.3, 'spanGaps' => true];
        }

        return ['labels' => $e->labels(), 'datasets' => $datasets];
    }
}
