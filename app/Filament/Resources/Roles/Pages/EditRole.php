<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\ActivityLog\ActivityLogger;
use App\Support\AccessControl;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $permissionNames = [];

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['grouped'] = RoleResource::group($this->record);

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->permissionNames = RoleResource::flatten($data['grouped'] ?? []);
        unset($data['grouped']);

        return $data;
    }

    protected function afterSave(): void
    {
        $before = $this->record->permissions()->pluck('name')->all();
        AccessControl::syncRolePermissions($this->record, $this->permissionNames);
        ActivityLogger::membershipChanged($this->record, 'Permissões', $before, $this->permissionNames);
    }
}
