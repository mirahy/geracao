<?php

namespace App\Filament\Resources\UnitRequests;

use App\Filament\Resources\UnitRequests\Pages\ManageUnitRequests;
use App\Models\UnitRequest;
use App\Services\UnitRequests\UnitRequestService;
use BackedEnum;
use DomainException;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use UnitEnum;

class UnitRequestResource extends Resource
{
    protected static ?string $model = UnitRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'solicitação de acesso';

    protected static ?string $pluralModelLabel = 'solicitações de acesso';

    protected static ?string $navigationLabel = 'Solicitações de acesso';

    protected static ?int $navigationSort = 3;

    public static function getNavigationBadge(): ?string
    {
        $pending = UnitRequest::where('status', UnitRequest::PENDING)->count();

        return $pending > 0 ? (string) $pending : null;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['user', 'consumerUnit', 'decider']);
    }

    /** Executa a decisão para cada pedido e resume o resultado em uma notificação. */
    private static function decide(Collection|UnitRequest $records, callable $decision, string $done): void
    {
        $failed = 0;
        $ok = 0;
        foreach ($records instanceof UnitRequest ? [$records] : $records as $record) {
            try {
                $decision($record);
                $ok++;
            } catch (DomainException) {
                $failed++;
            }
        }

        Notification::make()
            ->title($ok ? "{$ok} solicitação(ões) {$done}" : 'Nenhuma solicitação alterada')
            ->body($failed ? "{$failed} já tinha(m) sido respondida(s) e foi(ram) ignorada(s)." : null)
            ->{$ok ? 'success' : 'warning'}()
            ->send();
    }

    public static function table(Table $table): Table
    {
        $service = fn () => app(UnitRequestService::class);

        return $table
            ->columns([
                TextColumn::make('user.name')->label('Usuário')->description(fn (UnitRequest $r) => $r->user->email)->searchable(),
                TextColumn::make('consumerUnit.name')->label('Unidade')->description(fn (UnitRequest $r) => $r->consumerUnit->formatted_uc),
                TextColumn::make('message')->label('Mensagem')->placeholder('—')->limit(60)->wrap(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (string $state) => UnitRequest::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => UnitRequest::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('created_at')->label('Pedido em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('decider.name')->label('Respondido por')->placeholder('—')
                    ->description(fn (UnitRequest $r) => $r->decision_note),
            ])
            ->filters([
                SelectFilter::make('status')->label('Situação')->options(UnitRequest::STATUS_LABELS)->default(UnitRequest::PENDING),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('approve')->label('Aprovar')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (UnitRequest $r) => $r->isPending())
                    ->modalHeading('Aprovar solicitação')
                    ->modalDescription('O usuário passa a ver a unidade. O pedido é sempre de leitura; marque abaixo se ele também puder editar.')
                    ->schema([Toggle::make('can_edit')->label('Permitir edição da unidade')->default(false)])
                    ->action(fn (UnitRequest $record, array $data) => self::decide(
                        $record, fn (UnitRequest $r) => $service()->approve($r, auth()->user(), (bool) $data['can_edit']), 'aprovada(s)'
                    )),
                Action::make('reject')->label('Recusar')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (UnitRequest $r) => $r->isPending())
                    ->modalHeading('Recusar solicitação')
                    ->schema([Textarea::make('note')->label('Motivo (opcional, o usuário será avisado)')->rows(2)->maxLength(300)])
                    ->action(fn (UnitRequest $record, array $data) => self::decide(
                        $record, fn (UnitRequest $r) => $service()->reject($r, auth()->user(), $data['note'] ?? null), 'recusada(s)'
                    )),
            ])
            ->toolbarActions([
                BulkAction::make('approveSelected')->label('Aprovar (somente leitura)')->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->requiresConfirmation()
                    ->action(fn (Collection $records) => self::decide($records, fn (UnitRequest $r) => $service()->approve($r, auth()->user()), 'aprovada(s)'))
                    ->deselectRecordsAfterCompletion(),
                BulkAction::make('rejectSelected')->label('Recusar')->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->schema([Textarea::make('note')->label('Motivo (opcional)')->rows(2)->maxLength(300)])
                    ->action(fn (Collection $records, array $data) => self::decide($records, fn (UnitRequest $r) => $service()->reject($r, auth()->user(), $data['note'] ?? null), 'recusada(s)'))
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageUnitRequests::route('/')];
    }
}
