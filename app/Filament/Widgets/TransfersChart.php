<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Widgets\ChartWidget;

class TransfersChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 4;

    protected ?string $heading = 'Créditos transferidos por unidade (kWh)';

    protected ?string $description = 'Energia da UC geradora distribuída às demais, conforme o demonstrativo de compensação.';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $e = $this->energy();
        $datasets = [];
        foreach ($e->transfersSeries() as $name => $values) {
            $datasets[] = ['label' => $name, 'data' => $values, 'backgroundColor' => ChartPalette::color(count($datasets))];
        }

        return ['labels' => $e->labels(), 'datasets' => $datasets];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]]];
    }
}
