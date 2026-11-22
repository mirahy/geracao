<?php

namespace App\Policies;

use App\Models\User;

/** O registro de atividades é só de leitura e restrito a quem tem "logs.ver". */
class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('logs.ver');
    }

    public function view(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user): bool
    {
        return false;
    }

    public function delete(User $user): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
