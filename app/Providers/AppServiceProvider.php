<?php

namespace App\Providers;

use App\Policies\RolePolicy;
use App\Support\AccessControl;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // O perfil Administrador tem todas as permissões, inclusive as criadas no futuro.
        // Só vale para permissões por nome (ex.: "geracao.editar"); as regras das políticas (ex.: não excluir o próprio Administrador) continuam valendo.
        Gate::before(fn ($user, string $ability) => str_contains($ability, '.') && $user->hasRole(AccessControl::ADMIN_ROLE) ? true : null);

        Gate::policy(Role::class, RolePolicy::class);
    }
}
