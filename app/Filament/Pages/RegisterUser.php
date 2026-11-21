<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Support\AccessControl;
use Filament\Auth\Pages\Register;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/** Cadastro pela tela de login: perfil Consulta e status pendente até o administrador atribuir uma unidade. */
class RegisterUser extends Register
{
    protected function handleRegistration(array $data): Model
    {
        $user = User::create($data + ['status' => User::PENDING]);
        $user->assignRole(Role::findOrCreate(AccessControl::CONSULTA_ROLE, 'web'));

        return $user;
    }
}
