<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ImportDocumentsAction;
use App\Filament\Support\EnergyFilters;
use App\Filament\Widgets\ConsumptionChart;
use App\Filament\Widgets\CostChart;
use App\Filament\Widgets\CostCompositionChart;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Filament\Widgets\GenerationChart;
use App\Filament\Widgets\SavingsChart;
use App\Filament\Widgets\TransfersChart;
use App\Filament\Widgets\UnitsSummaryTable;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Schema;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Painel';

    protected static ?string $title = 'Painel de energia solar';

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->can('painel.ver');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return EnergyFilters::configure($schema);
    }

    public function getWidgets(): array
    {
        return [
            EnergyStatsOverview::class,
            GenerationChart::class,
            ConsumptionChart::class,
            TransfersChart::class,
            CostChart::class,
            SavingsChart::class,
            CostCompositionChart::class,
            UnitsSummaryTable::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [ImportDocumentsAction::make()];
    }
}
