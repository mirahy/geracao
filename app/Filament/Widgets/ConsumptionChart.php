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

    protected ?string $description = 'Consumo da rede por UC; a linha tracejada é o que a UC geradora consome direto das placas.';

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

        $self = $e->selfConsumptionSeries()['kwh'];
        if (array_filter($self, fn ($v) => $v !== null)) {
            $datasets[] = ['label' => 'Autoconsumo das placas (UC geradora)', 'data' => $self, 'borderColor' => '#6366f1', 'backgroundColor' => '#6366f1', 'borderDash' => [6, 4], 'tension' => 0.3, 'spanGaps' => true];
        }

        return ['labels' => $e->labels(), 'datasets' => $datasets];
    }
}
