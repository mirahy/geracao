<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base das políticas dos registros ligados a uma unidade consumidora:
 * permissão do perfil ("{prefixo}.ver" / "{prefixo}.editar") + acesso à unidade do registro.
 */
abstract class UnitScopedPolicy
{
    /** Prefixo das permissões, ex.: "geracao". */
    abstract protected function prefix(): string;

    /** Registro somente leitura (ex.: faturas importadas)? */
    protected function readOnly(): bool
    {
        return false;
    }

    /** Permissão exigida para criar/editar/excluir. */
    protected function editPermission(): string
    {
        return $this->prefix().'.editar';
    }

    public function viewAny(User $user): bool
    {
        return $user->can($this->prefix().'.ver');
    }

    public function view(User $user, Model $record): bool
    {
        return $user->can($this->prefix().'.ver') && $user->canViewUnit($record->accessibleUnitId());
    }

    public function create(User $user): bool
    {
        return ! $this->readOnly() && $user->can($this->editPermission());
    }

    public function update(User $user, Model $record): bool
    {
        return ! $this->readOnly() && $user->can($this->editPermission()) && $user->canEditUnit($record->accessibleUnitId());
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    public function deleteAny(User $user): bool
    {
        return $this->create($user);
    }
}
