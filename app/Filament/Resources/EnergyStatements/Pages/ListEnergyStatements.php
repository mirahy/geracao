<?php

namespace App\Filament\Resources\EnergyStatements\Pages;

use App\Filament\Actions\ImportDocumentsAction;
use App\Filament\Resources\EnergyStatements\EnergyStatementResource;
use Filament\Resources\Pages\ListRecords;

class ListEnergyStatements extends ListRecords
{
    protected static string $resource = EnergyStatementResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportDocumentsAction::make()];
    }
}
