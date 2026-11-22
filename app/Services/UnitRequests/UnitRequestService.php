<?php

namespace App\Services\UnitRequests;

use App\Filament\Resources\UnitRequests\UnitRequestResource;
use App\Models\ConsumerUnit;
use App\Models\UnitAccess;
use App\Models\UnitRequest;
use App\Models\User;
use App\Notifications\PanelNotification;
use App\Services\Notifications\Notifier;
use DomainException;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Regras dos pedidos de acesso a unidades: pedir, cancelar, aprovar e recusar. */
class UnitRequestService
{
    /**
     * Unidades que o usuário ainda não acessa e para as quais não há pedido pendente.
     *
     * @return Collection<int, ConsumerUnit>
     */
    public function availableUnits(User $user): Collection
    {
        $visible = $user->viewableUnitIds();
        if ($visible === null) {
            return collect(); // já vê todas as unidades
        }

        $pending = $user->unitRequests()->where('status', UnitRequest::PENDING)->pluck('consumer_unit_id')->all();

        return ConsumerUnit::withoutGlobalScopes()
            ->whereNotIn('id', [...$visible, ...$pending])
            ->orderBy('role')->orderBy('name')
            ->get();
    }

    /** @throws DomainException */
    public function submit(User $user, int $unitId, ?string $message = null): UnitRequest
    {
        if ($user->unitRequests()->where('status', UnitRequest::PENDING)->count() >= UnitRequest::MAX_PENDING) {
            throw new DomainException('Você já tem '.UnitRequest::MAX_PENDING.' solicitações pendentes. Aguarde a resposta ou cancele alguma.');
        }

        if (! $this->availableUnits($user)->contains('id', $unitId)) {
            throw new DomainException('Esta unidade não está disponível para solicitação (você já tem acesso ou já há um pedido pendente).');
        }

        $request = $user->unitRequests()->create([
            'consumer_unit_id' => $unitId,
            'message' => $message,
            'status' => UnitRequest::PENDING,
        ]);

        Notifier::send(Notifier::admins(), new PanelNotification(
            title: 'Solicitação de acesso a unidade',
            body: "{$user->name} ({$user->email}) pediu acesso à unidade {$request->consumerUnit->name}."
                .($message ? " Mensagem: {$message}" : ''),
            url: UnitRequestResource::getUrl('index'),
            actionLabel: 'Ver solicitações',
            icon: 'heroicon-o-key',
            color: 'warning',
        ));

        return $request;
    }

    /** @throws DomainException */
    public function cancel(UnitRequest $request, User $user): void
    {
        if ($request->user_id !== $user->id || ! $request->isPending()) {
            throw new DomainException('Só é possível cancelar os seus pedidos pendentes.');
        }

        $request->update(['status' => UnitRequest::CANCELLED, 'decided_at' => now()]);
    }

    /** Cria (ou amplia) o acesso do usuário à unidade e marca o pedido como aprovado. @throws DomainException */
    public function approve(UnitRequest $request, User $admin, bool $canEdit = false): void
    {
        $this->ensurePending($request);

        $user = $request->user;
        $wasPending = $user->isPending();

        DB::transaction(function () use ($request, $user, $admin, $canEdit) {
            $access = UnitAccess::firstOrNew(['user_id' => $user->id, 'consumer_unit_id' => $request->consumer_unit_id]);
            $access->can_edit = $access->exists ? ($access->can_edit || $canEdit) : $canEdit;
            $access->save();

            $request->update(['status' => UnitRequest::APPROVED, 'decided_by' => $admin->id, 'decided_at' => now()]);
        });

        // Cadastro pendente já recebe o aviso "Acesso liberado" ao ser ativado (UnitAccess).
        if (! $wasPending) {
            Notifier::send([$user], new PanelNotification(
                title: 'Solicitação aprovada',
                body: "Você agora tem acesso à unidade {$request->consumerUnit->name}".($canEdit ? ' (com edição).' : ' (somente leitura).'),
                url: Filament::getUrl(),
                actionLabel: 'Abrir o painel',
                icon: 'heroicon-o-check-circle',
                color: 'success',
            ));
        }
    }

    /** @throws DomainException */
    public function reject(UnitRequest $request, User $admin, ?string $note = null): void
    {
        $this->ensurePending($request);

        $request->update(['status' => UnitRequest::REJECTED, 'decided_by' => $admin->id, 'decided_at' => now(), 'decision_note' => $note]);

        Notifier::send([$request->user], new PanelNotification(
            title: 'Solicitação recusada',
            body: "Seu pedido de acesso à unidade {$request->consumerUnit->name} foi recusado.".($note ? " Motivo: {$note}" : ''),
            url: Filament::getUrl(),
            actionLabel: 'Abrir o painel',
            icon: 'heroicon-o-x-circle',
            color: 'danger',
        ));
    }

    private function ensurePending(UnitRequest $request): void
    {
        if (! $request->isPending()) {
            throw new DomainException('Este pedido já foi respondido.');
        }
    }
}
