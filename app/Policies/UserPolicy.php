<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('usuarios.gerenciar');
    }

    public function view(User $user, User $record): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function update(User $user, User $record): bool
    {
        return $this->viewAny($user);
    }

    /** Ninguém exclui a si mesmo. */
    public function delete(User $user, User $record): bool
    {
        return $this->viewAny($user) && $user->isNot($record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->viewAny($user);
    }
}
