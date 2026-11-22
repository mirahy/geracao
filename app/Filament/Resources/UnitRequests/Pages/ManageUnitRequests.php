<?php

namespace App\Filament\Resources\UnitRequests\Pages;

use App\Filament\Resources\UnitRequests\UnitRequestResource;
use Filament\Resources\Pages\ManageRecords;

class ManageUnitRequests extends ManageRecords
{
    protected static string $resource = UnitRequestResource::class;

    protected static ?string $title = 'Solicitações de acesso';
}
