<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\ActivityLog\ActivityLogger;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Events\Dispatcher;

/** Acessos ao painel (login, falha e logout). */
class ActivityLogSubscriber
{
    /** @return array<class-string, string> */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'onLogin',
            Failed::class => 'onFailed',
            Logout::class => 'onLogout',
        ];
    }

    public function onLogin(Login $event): void
    {
        if ($event->user instanceof User) {
            ActivityLogger::access('login', 'Entrou no painel', $event->user);
        }
    }

    public function onFailed(Failed $event): void
    {
        ActivityLogger::access('login_failed', 'Tentativa de login recusada: '.($event->credentials['email'] ?? '—'), null, ['email' => $event->credentials['email'] ?? null]);
    }

    public function onLogout(Logout $event): void
    {
        if ($event->user instanceof User) {
            ActivityLogger::access('logout', 'Saiu do painel', $event->user);
        }
    }
}
