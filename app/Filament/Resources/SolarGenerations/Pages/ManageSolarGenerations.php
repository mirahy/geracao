<?php

namespace App\Filament\Resources\SolarGenerations\Pages;

use App\Filament\Actions\ImportSolarProductionAction;
use App\Filament\Actions\ReviewSolarProductionAction;
use App\Filament\Resources\SolarGenerations\SolarGenerationResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageSolarGenerations extends ManageRecords
{
    protected static string $resource = SolarGenerationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ReviewSolarProductionAction::make(),
            ImportSolarProductionAction::make(),
            CreateAction::make()->label('Informar geração'),
        ];
    }
}
