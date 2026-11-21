<?php

namespace App\Filament\Resources\ConsumerUnits\Pages;

use App\Filament\Resources\ConsumerUnits\ConsumerUnitResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditConsumerUnit extends EditRecord
{
    protected static string $resource = ConsumerUnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
