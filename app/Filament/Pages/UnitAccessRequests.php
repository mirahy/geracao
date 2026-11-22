<?php

namespace App\Filament\Pages;

use App\Models\UnitRequest;
use App\Models\User;
use App\Services\UnitRequests\UnitRequestService;
use App\Support\Mask;
use DomainException;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** Pedidos de acesso a outras unidades feitos pelo próprio usuário (somente leitura; o administrador decide). */
class UnitAccessRequests extends Page implements HasTable
{
    use InteractsWithTable;

    protected static ?string $slug = 'solicitar-acesso';

    protected static ?string $title = 'Solicitar acesso a unidades';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.pages.unit-access-requests';

    protected function getHeaderActions(): array
    {
        return [
            Action::make('newRequest')->label('Nova solicitação')->icon(Heroicon::OutlinedPaperAirplane)
                ->modalHeading('Solicitar acesso a uma unidade')
                ->modalDescription('O acesso pedido é de visualização. O administrador será avisado e pode aprovar ou recusar.')
                ->modalSubmitActionLabel('Enviar solicitação')
                ->schema([
                    Select::make('consumer_unit_id')->label('Unidade consumidora')
                        ->options(fn () => app(UnitRequestService::class)->availableUnits(auth()->user())
                            ->mapWithKeys(fn ($unit) => [$unit->id => Mask::unit($unit)])->all())
                        ->searchable()->required()
                        ->helperText('Por segurança, parte do número e do endereço aparece oculta.'),
                    Textarea::make('message')->label('Mensagem para o administrador (opcional)')->rows(3)->maxLength(500),
                ])
                ->action(function (array $data): void {
                    try {
                        app(UnitRequestService::class)->submit(auth()->user(), (int) $data['consumer_unit_id'], $data['message'] ?? null);
                    } catch (DomainException $e) {
                        Notification::make()->title('Não foi possível enviar')->body($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title('Solicitação enviada')->body('Você receberá um aviso quando o administrador responder.')->success()->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => UnitRequest::query()->where('user_id', auth()->id())->with(['consumerUnit', 'decider']))
            ->heading('Minhas solicitações')
            ->description('Até '.UnitRequest::MAX_PENDING.' solicitações pendentes ao mesmo tempo.')
            ->emptyStateHeading('Você ainda não fez nenhuma solicitação')
            ->columns([
                TextColumn::make('consumerUnit.name')->label('Unidade')->description(fn (UnitRequest $r) => $r->consumerUnit->formatted_uc),
                TextColumn::make('message')->label('Mensagem')->placeholder('—')->limit(60)->wrap(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (string $state) => UnitRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => UnitRequest::STATUS_COLORS[$state] ?? 'gray')
                    ->description(fn (UnitRequest $r) => $r->decision_note ? "Motivo: {$r->decision_note}" : null),
                TextColumn::make('created_at')->label('Pedido em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('decided_at')->label('Respondido em')->dateTime('d/m/Y H:i')->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('cancel')->label('Cancelar')->icon(Heroicon::OutlinedXMark)->color('gray')
                    ->visible(fn (UnitRequest $r) => $r->isPending())
                    ->requiresConfirmation()
                    ->action(function (UnitRequest $record): void {
                        try {
                            app(UnitRequestService::class)->cancel($record, auth()->user());
                            Notification::make()->title('Solicitação cancelada')->success()->send();
                        } catch (DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();
                        }
                    }),
            ]);
    }

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ! $user->isPending();
    }
}
