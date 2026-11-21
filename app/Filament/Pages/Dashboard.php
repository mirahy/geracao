<?php

namespace App\Filament\Pages;

use App\Filament\Actions\ImportDocumentsAction;
use App\Filament\Widgets\ConsumptionChart;
use App\Filament\Widgets\CostChart;
use App\Filament\Widgets\CostCompositionChart;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Filament\Widgets\GenerationChart;
use App\Filament\Widgets\SavingsChart;
use App\Filament\Widgets\TransfersChart;
use App\Filament\Widgets\UnitsSummaryTable;
use App\Models\ConsumerUnit;
use App\Services\Dashboard\EnergyDashboard;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

class Dashboard extends BaseDashboard
{
    use HasFiltersForm;

    protected static ?string $navigationLabel = 'Painel';

    protected static ?string $title = 'Painel de energia solar';

    public function filtersForm(Schema $schema): Schema
    {
        $months = EnergyDashboard::availableMonths();

        return $schema->components([
            Section::make()
                ->icon(Heroicon::OutlinedFunnel)
                ->schema([
                    Select::make('unit_id')
                        ->label('Unidade consumidora')
                        ->placeholder('Todas as unidades')
                        ->options(fn () => ConsumerUnit::orderBy('name')->pluck('name', 'id'))
                        ->searchable(),
                    Select::make('from')
                        ->label('Mês inicial')
                        ->placeholder('Últimos 12 meses')
                        ->options($months),
                    Select::make('to')
                        ->label('Mês final')
                        ->placeholder('Mais recente')
                        ->options($months),
                ])
                ->columns(3)
                ->columnSpanFull(),
        ]);
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

    public function getColumns(): int | array
    {
        return 2;
    }

    protected function getHeaderActions(): array
    {
        return [ImportDocumentsAction::make()];
    }
}
