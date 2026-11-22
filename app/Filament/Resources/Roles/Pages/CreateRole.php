<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use App\Services\ActivityLog\ActivityLogger;
use App\Support\AccessControl;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    /** @var list<string> */
    private array $permissionNames = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissionNames = RoleResource::flatten($data['grouped'] ?? []);
        unset($data['grouped']);

        return $data;
    }

    protected function afterCreate(): void
    {
        AccessControl::syncRolePermissions($this->record, $this->permissionNames);
        ActivityLogger::membershipChanged($this->record, 'Permissões', [], $this->permissionNames);
    }
}
