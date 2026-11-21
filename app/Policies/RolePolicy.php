<?php

namespace App\Policies;

use App\Models\User;
use App\Support\AccessControl;
use Spatie\Permission\Models\Role;

class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('perfis.gerenciar');
    }

    public function view(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, Role $role): bool
    {
        return $this->viewAny($user);
    }

    /** O perfil Administrador e perfis em uso por usuários não podem ser excluídos. */
    public function delete(User $user, Role $role): bool
    {
        return $this->viewAny($user) && $role->name !== AccessControl::ADMIN_ROLE && $role->users()->doesntExist();
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
