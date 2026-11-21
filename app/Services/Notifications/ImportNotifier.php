<?php

namespace App\Services\Notifications;

use App\Filament\Resources\ImportedDocuments\ImportedDocumentResource;
use App\Models\ConsumerUnit;
use App\Models\ImportedDocument;
use App\Models\User;
use App\Notifications\PanelNotification;
use App\Support\AccessControl;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Avisa quem acompanha as unidades (administradores e usuários com acesso à unidade) sobre
 * faturas e demonstrativos importados. Um aviso por importação, não por arquivo.
 */
class ImportNotifier
{
    /** @param  Collection<int, ImportedDocument>  $documents  documentos importados com sucesso */
    public static function notify(Collection $documents): void
    {
        $documents = $documents->filter(fn (ImportedDocument $d) => $d->status === 'success' && $d->type);
        if ($documents->isEmpty()) {
            return;
        }

        $actorId = Auth::id();
        $recipients = User::with('roles')->where('status', User::ACTIVE)->get()
            ->reject(fn (User $user) => $user->id === $actorId);

        foreach ($recipients as $user) {
            $visible = $documents->filter(fn (ImportedDocument $d) => $user->hasRole(AccessControl::ADMIN_ROLE) || $user->canViewUnit($d->consumer_unit_id));
            if ($visible->isEmpty()) {
                continue;
            }

            Notifier::send([$user], new PanelNotification(
                title: 'Novos documentos importados',
                body: self::summary($visible),
                url: $user->can('documentos.ver') && $user->hasGeneratorAccess() ? ImportedDocumentResource::getUrl() : Filament::getUrl(),
                actionLabel: 'Ver importações',
                icon: 'heroicon-o-document-arrow-up',
                color: 'info',
            ));
        }
    }

    private static function summary(Collection $documents): string
    {
        $invoices = $documents->where('type', ImportedDocument::TYPE_INVOICE)->count();
        $statements = $documents->where('type', ImportedDocument::TYPE_STATEMENT)->count();

        $names = ConsumerUnit::withoutGlobalScopes()->whereIn('id', $documents->pluck('consumer_unit_id')->filter())->pluck('name', 'id');
        $units = $documents->map(fn (ImportedDocument $d) => $names[$d->consumer_unit_id] ?? null)->filter()->unique()->values();
        $months = $documents->map(fn (ImportedDocument $d) => $d->reference_month?->format('m/Y'))->filter()->unique()->sort()->values();

        return collect([
            $invoices ? "{$invoices} fatura(s)" : null,
            $statements ? "{$statements} demonstrativo(s) de compensação" : null,
        ])->filter()->implode(' e ').' importado(s)'
            .($units->isNotEmpty() ? ' — '.$units->implode(', ') : '')
            .($months->isNotEmpty() ? ' ('.$months->implode(', ').')' : '').'.';
    }
}
