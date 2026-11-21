<?php

namespace App\Filament\Resources\EnergyStatements\Pages;

use App\Filament\Actions\ViewOriginalAction;
use App\Filament\Resources\EnergyStatements\EnergyStatementResource;
use Filament\Resources\Pages\ViewRecord;

class ViewEnergyStatement extends ViewRecord
{
    protected static string $resource = EnergyStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewOriginalAction::make()];
    }
}
