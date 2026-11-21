<?php

namespace App\Filament\Resources\ConsumerUnits\Pages;

use App\Filament\Resources\ConsumerUnits\ConsumerUnitResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListConsumerUnits extends ListRecords
{
    protected static string $resource = ConsumerUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
