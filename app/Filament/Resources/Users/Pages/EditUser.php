<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Services\ActivityLog\ActivityLogger;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    /** @var list<string> */
    private array $rolesBefore = [];

    protected function beforeSave(): void
    {
        $this->rolesBefore = $this->record->roles()->pluck('name')->all();
    }

    protected function afterSave(): void
    {
        ActivityLogger::membershipChanged($this->record, 'Perfis', $this->rolesBefore, $this->record->roles()->pluck('name')->all());
    }

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
