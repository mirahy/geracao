<?php

namespace App\Filament\Resources\SolarInstallments\Pages;

use App\Filament\Resources\SolarInstallments\SolarInstallmentResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSolarInstallments extends ManageRecords
{
    protected static string $resource = SolarInstallmentResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Informar parcela')];
    }
}
