<?php

namespace App\Services\Notifications;

use App\Models\User;
use App\Notifications\PanelNotification;
use App\Support\AccessControl;
use Illuminate\Support\Collection;
use Throwable;

/** Envia avisos ao painel e por e-mail sem que uma falha de e-mail derrube a ação original. */
class Notifier
{
    /** @return Collection<int, User> administradores ativos */
    public static function admins(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('name', AccessControl::ADMIN_ROLE))->where('status', User::ACTIVE)->get();
    }

    /** @param iterable<User> $users */
    public static function send(iterable $users, PanelNotification $notification): void
    {
        foreach ($users as $user) {
            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}
