<?php

namespace App\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\ConsumerUnit;
use App\Models\UnitRequest;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'usuário';

    protected static ?string $pluralModelLabel = 'usuários';

    protected static ?string $navigationLabel = 'Usuários';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Acesso')->columns(2)->schema([
                TextInput::make('name')->label('Nome')->required()->maxLength(255),
                TextInput::make('email')->label('E-mail')->email()->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('password')->label('Senha')->password()->revealable()
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn (?string $state) => filled($state))
                    ->minLength(8)
                    ->helperText(fn (string $operation) => $operation === 'edit' ? 'Deixe em branco para manter a senha atual.' : null),
                Select::make('status')->label('Situação')->required()->default(User::ACTIVE)
                    ->options([User::ACTIVE => 'Ativo', User::PENDING => 'Pendente (aguardando unidade)'])
                    ->helperText('Cadastros novos ficam pendentes e são liberados automaticamente ao receber uma unidade.'),
                Select::make('roles')->label('Perfis')
                    ->relationship('roles', 'name')->multiple()->preload()
                    ->helperText('As permissões do usuário são a soma dos perfis escolhidos.'),
            ]),
            Section::make('Unidades consumidoras')
                ->description('Unidades que o usuário pode ver e, marcando "Pode editar", alterar. Perfis com "Ver todas as unidades" / "Editar todas as unidades" ignoram esta lista.')
                ->schema([
                    Repeater::make('unitAccesses')->label('Unidades')
                        ->relationship()
                        ->addActionLabel('Adicionar unidade')
                        ->defaultItems(0)
                        ->columns(2)
                        ->schema([
                            Select::make('consumer_unit_id')->label('Unidade')
                                ->options(fn () => ConsumerUnit::orderBy('role')->orderBy('name')->pluck('name', 'id'))
                                ->required()->searchable()
                                ->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                            Toggle::make('can_edit')->label('Pode editar')->default(false)->inline(false),
                        ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->sortable(),
                TextColumn::make('email')->label('E-mail')->searchable(),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn ($state) => $state === User::PENDING ? 'Pendente' : 'Ativo')
                    ->color(fn ($state) => $state === User::PENDING ? 'warning' : 'success'),
                TextColumn::make('requested_unit')->label('Unidade solicitada')
                    ->state(fn (User $record) => $record->unitRequests()->where('status', UnitRequest::PENDING)->latest()->first()?->consumerUnit?->name)
                    ->placeholder('—'),
                TextColumn::make('roles.name')->label('Perfis')->badge(),
                TextColumn::make('unit_accesses_count')->label('Unidades')->counts('unitAccesses')->alignEnd(),
            ])
            ->recordActions([
                Action::make('grantRequestedUnit')->label('Liberar unidade solicitada')
                    ->icon(Heroicon::OutlinedKey)->color('success')
                    ->visible(fn (User $record) => $record->isPending() && $record->unitRequests()->where('status', UnitRequest::PENDING)->exists())
                    ->requiresConfirmation()
                    ->modalDescription('O usuário passa a ver a unidade solicitada (somente leitura) e o acesso é liberado. Você pode ajustar depois na edição do usuário.')
                    ->action(function (User $record): void {
                        $request = $record->unitRequests()->where('status', UnitRequest::PENDING)->latest()->first();
                        $record->unitAccesses()->firstOrCreate(['consumer_unit_id' => $request->consumer_unit_id], ['can_edit' => false]);
                        Notification::make()->title('Acesso liberado')->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
