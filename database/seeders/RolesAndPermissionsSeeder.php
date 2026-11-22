<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\AccessControl;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Cria (de forma idempotente) permissões e perfis padrão e garante um administrador. */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $created = [];
        foreach (array_keys(AccessControl::all()) as $name) {
            if (Permission::findOrCreate($name, 'web')->wasRecentlyCreated) {
                $created[] = $name;
            }
        }

        foreach (AccessControl::defaultRoles() as $name => $permissions) {
            $role = Role::findOrCreate($name, 'web');

            // O Administrador acompanha o catálogo; os demais perfis só recebem as permissões na criação,
            // para não desfazer ajustes feitos na tela de perfis. Permissões novas do catálogo entram
            // nos perfis que as incluem por padrão.
            if ($name === AccessControl::ADMIN_ROLE || $role->wasRecentlyCreated) {
                $role->syncPermissions($permissions);
            } elseif ($added = array_intersect($permissions, $created)) {
                $role->givePermissionTo($added);
            }
        }

        // Instalações existentes: sem nenhum administrador, o usuário mais antigo assume o papel.
        if (User::role(AccessControl::ADMIN_ROLE)->doesntExist() && ($first = User::orderBy('id')->first())) {
            $first->assignRole(AccessControl::ADMIN_ROLE);
        }
    }
}
