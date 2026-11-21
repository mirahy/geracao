<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Widgets\ChartWidget;

class GenerationChart extends ChartWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 2;

    protected ?string $heading = 'Geração, injeção e autoconsumo (kWh)';

    protected ?string $description = 'Geração informada das placas x energia injetada na rede; autoconsumo = geração − injetado.';

    protected int | string | array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $e = $this->energy();
        $s = $e->generationSeries();

        return [
            'labels' => $e->labels(),
            'datasets' => [
                ['label' => 'Geração das placas', 'data' => $s['generated'], 'backgroundColor' => '#f59e0b', 'borderRadius' => 4],
                ['label' => 'Injetado na rede', 'data' => $s['injected'], 'backgroundColor' => '#10b981', 'borderRadius' => 4],
                ['label' => 'Autoconsumo', 'data' => $s['self_consumed'], 'backgroundColor' => '#6366f1', 'borderRadius' => 4],
                ['label' => 'Consumo da rede (UC geradora)', 'data' => $s['grid_consumption'], 'type' => 'line', 'borderColor' => '#64748b', 'backgroundColor' => '#64748b', 'tension' => 0.3],
            ],
        ];
    }
}
