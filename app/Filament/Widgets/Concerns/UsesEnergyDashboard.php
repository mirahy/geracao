<?php

namespace App\Filament\Widgets\Concerns;

use App\Services\Dashboard\EnergyDashboard;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Illuminate\Database\Eloquent\Model;

/**
 * Widgets que leem os filtros do dashboard (UC, período) ou,
 * quando exibidos na página de uma UC, o registro atual.
 */
trait UsesEnergyDashboard
{
    use InteractsWithPageFilters;

    public ?Model $record = null;

    protected function energy(): EnergyDashboard
    {
        $filters = $this->pageFilters ?? [];

        return EnergyDashboard::make(
            $this->record?->getKey() ?? ($filters['unit_id'] ?? null),
            $filters['from'] ?? null,
            $filters['to'] ?? null,
        );
    }
}
