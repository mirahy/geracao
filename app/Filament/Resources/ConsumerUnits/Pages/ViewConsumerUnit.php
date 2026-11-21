<?php

namespace App\Filament\Resources\ConsumerUnits\Pages;

use App\Filament\Resources\ConsumerUnits\ConsumerUnitResource;
use App\Filament\Support\EnergyFilters;
use App\Filament\Widgets\ConsumptionChart;
use App\Filament\Widgets\CostChart;
use App\Filament\Widgets\EnergyStatsOverview;
use App\Filament\Widgets\GenerationChart;
use App\Filament\Widgets\SavingsChart;
use App\Filament\Widgets\TransfersChart;
use Filament\Actions\EditAction;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Schema;

class ViewConsumerUnit extends ViewRecord
{
    use HasFiltersForm;

    protected static string $resource = ConsumerUnitResource::class;

    /** Cada unidade começa com o período padrão (não reaproveita filtros da sessão). */
    public function persistsFiltersInSession(): bool
    {
        return false;
    }

    /** Filtros de período, iguais aos do painel (a unidade é a do próprio registro). */
    public function filtersForm(Schema $schema): Schema
    {
        return EnergyFilters::configure($schema, withUnit: false);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            $this->getFiltersFormContentComponent(),
            $this->getInfolistContentComponent(),
            $this->getRelationManagersContentComponent(),
        ]);
    }

    public function getFiltersFormContentComponent(): Component
    {
        return EmbeddedSchema::make('filtersForm');
    }

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

    public function getFooterWidgetsColumns(): int|array
    {
        return 2;
    }
}
