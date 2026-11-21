<?php

namespace App\Filament\Resources\SolarGenerations;

use App\Filament\Resources\SolarGenerations\Pages\ManageSolarGenerations;
use App\Models\ConsumerUnit;
use App\Models\EnergyHistory;
use App\Models\SolarGeneration;
use App\Support\MonthOptions;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Validation\Rules\Unique;
use UnitEnum;

class SolarGenerationResource extends Resource
{
    protected static ?string $model = SolarGeneration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSun;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?string $modelLabel = 'geração solar';

    protected static ?string $pluralModelLabel = 'gerações solares';

    protected static ?string $navigationLabel = 'Geração das placas';

    protected static ?int $navigationSort = 2;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('consumer_unit_id')
                ->label('Unidade geradora')
                ->options(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->orderBy('name')->pluck('name', 'id'))
                ->default(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_GENERATOR)->value('id'))
                ->required()
                ->live(),
            Select::make('reference_month')
                ->label('Mês de referência')
                ->options(MonthOptions::recent())
                ->required()
                ->searchable()
                ->unique(
                    ignoreRecord: true,
                    modifyRuleUsing: fn (Unique $rule, $get) => $rule->where('consumer_unit_id', $get('consumer_unit_id')),
                )
                ->validationMessages(['unique' => 'Já existe geração cadastrada para esta unidade neste mês.'])
                ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('Y-m-d') : null),
            TextInput::make('generated_kwh')
                ->label('Geração total no mês')
                ->numeric()->minValue(0)->suffix('kWh')->required()
                ->helperText('Total gerado pelas placas no mês (valor do inversor/aplicativo).'),
            Textarea::make('notes')->label('Observações')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_month')->label('Mês')
                    ->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('F/Y')))->sortable(),
                TextColumn::make('consumerUnit.name')->label('Unidade')->searchable(),
                TextColumn::make('generated_kwh')->label('Geração (kWh)')->numeric(decimalPlaces: 2, decimalSeparator: ',', thousandsSeparator: '.')->alignEnd()->sortable(),
                TextColumn::make('injected')->label('Injetado (kWh)')->alignEnd()
                    ->state(fn (SolarGeneration $r) => self::history($r)?->injected_kwh)
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: '.')->placeholder('—'),
                TextColumn::make('self')->label('Autoconsumo (kWh)')->alignEnd()
                    ->state(fn (SolarGeneration $r) => ($h = self::history($r)) ? max($r->generated_kwh - $h->injected_kwh, 0) : null)
                    ->numeric(decimalPlaces: 0, decimalSeparator: ',', thousandsSeparator: '.')->placeholder('—'),
                TextColumn::make('source')->label('Origem')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'planilha' ? 'Planilha' : 'Manual')
                    ->color(fn ($state) => $state === 'planilha' ? 'info' : 'gray'),
                TextColumn::make('notes')->label('Obs.')->limit(30)->toggleable(),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([
                EditAction::make()->mutateFormDataUsing(fn (array $data) => $data + ['source' => 'manual']),
                DeleteAction::make(),
            ])
            ->toolbarActions([DeleteBulkAction::make()]);
    }

    private static function history(SolarGeneration $record)
    {
        return EnergyHistory::where('consumer_unit_id', $record->consumer_unit_id)
            ->where('reference_month', $record->reference_month->format('Y-m-d'))->first();
    }

    public static function getPages(): array
    {
        return ['index' => ManageSolarGenerations::route('/')];
    }
}
