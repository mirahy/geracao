<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Support\AccessControl;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use UnitEnum;

class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static string|UnitEnum|null $navigationGroup = 'Administração';

    protected static ?string $modelLabel = 'perfil';

    protected static ?string $pluralModelLabel = 'perfis';

    protected static ?string $navigationLabel = 'Perfis e permissões';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        $sections = [];
        foreach (AccessControl::groups() as $group => $permissions) {
            $sections[] = Section::make($group)->compact()->schema([
                CheckboxList::make('grouped.'.Str::slug($group, '_'))->hiddenLabel()->options($permissions),
            ]);
        }

        return $schema->components([
            Section::make('Perfil')->schema([
                Hidden::make('guard_name')->default('web'),
                TextInput::make('name')->label('Nome do perfil')->required()->maxLength(255)
                    ->unique(ignoreRecord: true)
                    ->disabled(fn (?Role $record) => $record?->name === AccessControl::ADMIN_ROLE)
                    ->dehydrated()
                    ->helperText(fn (?Role $record) => $record?->name === AccessControl::ADMIN_ROLE
                        ? 'O Administrador tem todas as permissões e não pode ser renomeado.' : null),
            ]),
            Section::make('Permissões')->columns(2)->schema($sections),
        ]);
    }

    /** Nomes das permissões marcadas, a partir do estado agrupado do formulário. @return list<string> */
    public static function flatten(array $grouped): array
    {
        return array_values(array_unique(array_merge(...array_map(fn ($names) => array_values((array) $names), array_values($grouped) ?: [[]]))));
    }

    /** Estado agrupado do formulário para as permissões atuais do perfil. @return array<string, list<string>> */
    public static function group(Role $role): array
    {
        $current = $role->permissions->pluck('name')->all();
        $grouped = [];
        foreach (AccessControl::groups() as $group => $permissions) {
            $grouped[Str::slug($group, '_')] = array_values(array_intersect(array_keys($permissions), $current));
        }

        return $grouped;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Perfil')->searchable()->sortable(),
                TextColumn::make('permissions_count')->label('Permissões')->counts('permissions')->alignEnd(),
                TextColumn::make('users_count')->label('Usuários')->counts('users')->alignEnd(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
