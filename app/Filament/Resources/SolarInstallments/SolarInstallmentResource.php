<?php

namespace App\Filament\Resources\SolarInstallments;

use App\Filament\Resources\SolarInstallments\Pages\ManageSolarInstallments;
use App\Models\ConsumerUnit;
use App\Models\SolarInstallment;
use App\Support\MonthOptions;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class SolarInstallmentResource extends Resource
{
    protected static ?string $model = SolarInstallment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?string $modelLabel = 'parcela das placas';

    protected static ?string $pluralModelLabel = 'parcelas das placas';

    protected static ?string $navigationLabel = 'Parcelas das placas';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        $months = MonthOptions::recent(84, 84);

        return $schema->components([
            Select::make('consumer_unit_id')->label('Unidade geradora')
                ->options(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->value('id'))
                ->required(),
            TextInput::make('amount')->label('Valor da parcela (por mês)')->numeric()->minValue(0)->prefix('R$')->required(),
            Select::make('start_month')->label('Mês inicial')->options($months)->searchable()->required()
                ->formatStateUsing(fn ($state) => $state ? \Carbon\Carbon::parse($state)->format('Y-m-d') : null)
                ->helperText('Para informar um único mês, use o mesmo mês inicial e final.'),
            Select::make('end_month')->label('Mês final')->options($months)->searchable()
                ->formatStateUsing(fn ($state) => $state ? \Carbon\Carbon::parse($state)->format('Y-m-d') : null)
                ->afterOrEqual('start_month')
                ->helperText('Deixe em branco se a parcela continua sem data final.'),
            TextInput::make('description')->label('Descrição')->placeholder('Financiamento das placas')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        $month = fn ($state) => $state ? ucfirst($state->translatedFormat('M/Y')) : null;

        return $table
            ->columns([
                TextColumn::make('consumerUnit.name')->label('Unidade'),
                TextColumn::make('description')->label('Descrição')->placeholder('—'),
                TextColumn::make('amount')->label('Parcela mensal')->money('BRL', locale: 'pt_BR')->alignEnd(),
                TextColumn::make('start_month')->label('De')->formatStateUsing($month)->sortable(),
                TextColumn::make('end_month')->label('Até')->formatStateUsing($month)->placeholder('sem data final'),
            ])
            ->defaultSort('start_month', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSolarInstallments::route('/')];
    }
}
