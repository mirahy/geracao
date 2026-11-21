<?php

namespace App\Filament\Resources\ConsumerUnits\Pages;

use App\Filament\Resources\ConsumerUnits\ConsumerUnitResource;
use App\Filament\Widgets\ConsumptionChart;
use App\Filament\Widgets\CostChart;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Filament\Widgets\GenerationChart;
use App\Filament\Widgets\SavingsChart;
use App\Filament\Widgets\TransfersChart;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewConsumerUnit extends ViewRecord
{
    protected static string $resource = ConsumerUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [EditAction::make()];
    }

    /** Dashboard da própria unidade consumidora. */
    protected function getFooterWidgets(): array
    {
        $widgets = [EnergyStatsOverview::class];

        if ($this->getRecord()->isGenerator()) {
            $widgets[] = GenerationChart::class;
        }

        return [...$widgets, ConsumptionChart::class, TransfersChart::class, CostChart::class, SavingsChart::class];
    }

    public function getFooterWidgetsColumns(): int | array
    {
        return 2;
    }
}
