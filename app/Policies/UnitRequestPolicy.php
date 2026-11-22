<?php

namespace App\Policies;

use App\Models\UnitRequest;
use App\Models\User;

/** Quem responde aos pedidos de acesso é quem tem "solicitacoes.gerenciar" (Administrador). */
class UnitRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('solicitacoes.gerenciar');
    }

    public function view(User $user, UnitRequest $request): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UnitRequest $request): bool
    {
        return $this->viewAny($user);
    }

    public function delete(User $user, UnitRequest $request): bool
    {
        return false;
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
