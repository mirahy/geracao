<?php

namespace App\Filament\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\ConsumerUnit;
use App\Models\UnitRequest;
use App\Models\User;
use App\Notifications\PanelNotification;
use App\Services\Notifications\Notifier;
use App\Support\Mask;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Tela única dos cadastros pendentes: solicitar a atribuição de uma unidade ao administrador. */
class PendingApproval extends Page
{
    protected static ?string $slug = 'aguardando-liberacao';

    protected static ?string $title = 'Aguardando liberação';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.pending-approval';

    public function mount(): void
    {
        $user = auth()->user();

        if (! $user instanceof User || ! $user->isPending()) {
            $this->redirect(Dashboard::getUrl());

            return;
        }
    }

    /** Chamado pela página ao carregar (wire:init): abre o modal enquanto não há solicitação. */
    public function openRequestModal(): void
    {
        if (! $this->openRequest() && ! $this->mountedActions) {
            $this->mountAction('requestUnit');
        }
    }

    public function openRequest(): ?UnitRequest
    {
        return auth()->user()?->unitRequests()->where('status', UnitRequest::PENDING)->with('consumerUnit')->latest()->first();
    }

    public function requestedUnitLabel(): ?string
    {
        $unit = $this->openRequest()?->consumerUnit;

        return $unit ? Mask::unit($unit) : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestUnit')
                ->label(fn () => $this->openRequest() ? 'Alterar solicitação' : 'Solicitar unidade')
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Solicitar acesso a uma unidade')
                ->modalDescription('Escolha a unidade consumidora a que você precisa ter acesso. O administrador será avisado e liberará o seu cadastro.')
                ->modalSubmitActionLabel('Enviar solicitação')
                ->schema([
                    Select::make('consumer_unit_id')->label('Unidade consumidora')
                        ->options(fn () => ConsumerUnit::withoutGlobalScopes()->orderBy('role')->orderBy('name')->get()
                            ->mapWithKeys(fn (ConsumerUnit $unit) => [$unit->id => Mask::unit($unit)])->all())
                        ->default(fn () => $this->openRequest()?->consumer_unit_id)
                        ->searchable()->required()
                        ->helperText('Por segurança, parte do número e do endereço aparece oculta.'),
                    Textarea::make('message')->label('Mensagem para o administrador (opcional)')->rows(3)->maxLength(500)
                        ->default(fn () => $this->openRequest()?->message),
                ])
                ->action(function (array $data): void {
                    /** @var User $user */
                    $user = auth()->user();

                    $user->unitRequests()->where('status', UnitRequest::PENDING)->delete();
                    $unitRequest = $user->unitRequests()->create($data + ['status' => UnitRequest::PENDING]);

                    Notifier::send(Notifier::admins(), new PanelNotification(
                        title: 'Solicitação de acesso a unidade',
                        body: "{$user->name} ({$user->email}) pediu acesso à unidade {$unitRequest->consumerUnit->name}."
                            .($unitRequest->message ? " Mensagem: {$unitRequest->message}" : ''),
                        url: UserResource::getUrl('edit', ['record' => $user]),
                        actionLabel: 'Atribuir unidade',
                        icon: 'heroicon-o-key',
                        color: 'warning',
                    ));

                    Notification::make()->title('Solicitação enviada')->body('Você receberá um aviso quando o administrador liberar o seu acesso.')->success()->send();
                }),
        ];
    }
}
