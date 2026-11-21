<?php

namespace App\Http\Middleware;

use App\Filament\Pages\PendingApproval;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Cadastros pendentes só enxergam a tela de solicitação de unidade (e podem sair). */
class RedirectPendingUsers
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->isPending()
            && ! $request->routeIs(PendingApproval::getRouteName(), 'filament.admin.auth.logout', 'livewire.*')) {
            return redirect(PendingApproval::getUrl());
        }

        return $next($request);
    }
}
