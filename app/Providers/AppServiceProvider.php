<?php

namespace App\Providers;

use App\Listeners\ActivityLogSubscriber;
use App\Models\ActivityLog;
use App\Policies\ActivityLogPolicy;
use App\Policies\RolePolicy;
use App\Services\ActivityLog\ActivityLogger;
use App\Support\AccessControl;
use Illuminate\Support\Facades\Event;
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
        Gate::before(function ($user, string $ability) {
            if ($user->isPending()) {
                return false; // cadastro aguardando liberação: nenhuma permissão
            }

            return str_contains($ability, '.') && $user->hasRole(AccessControl::ADMIN_ROLE) ? true : null;
        });

        Gate::policy(Role::class, RolePolicy::class);
        Gate::policy(ActivityLog::class, ActivityLogPolicy::class);

        Event::subscribe(ActivityLogSubscriber::class);

        // Perfis (Spatie Permission) não usam o trait dos models: registramos criação, renomeação e exclusão aqui.
        Role::created(fn (Role $role) => ActivityLogger::changed('created', "Criou perfil: {$role->name}", $role));
        Role::updated(function (Role $role): void {
            if ($role->wasChanged('name')) {
                ActivityLogger::changed('updated', "Renomeou perfil: {$role->getOriginal('name')} → {$role->name}", $role, ['old' => ['name' => $role->getOriginal('name')], 'attributes' => ['name' => $role->name]]);
            }
        });
        Role::deleted(fn (Role $role) => ActivityLogger::changed('deleted', "Excluiu perfil: {$role->name}", $role));
    }
}
