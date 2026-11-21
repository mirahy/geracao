<?php

namespace App\Filament\Resources\EnergyStatements;

use App\Filament\Actions\ViewOriginalAction;
use App\Filament\Resources\EnergyStatements\Pages\ListEnergyStatements;
use App\Filament\Resources\EnergyStatements\Pages\ViewEnergyStatement;
use App\Filament\Resources\EnergyStatements\RelationManagers\TransfersRelationManager;
use App\Models\ConsumerUnit;
use App\Models\EnergyStatement;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class EnergyStatementResource extends Resource
{
    protected static ?string $model = EnergyStatement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static string|UnitEnum|null $navigationGroup = 'Documentos';

    protected static ?string $modelLabel = 'demonstrativo de compensação';

    protected static ?string $pluralModelLabel = 'demonstrativos de compensação';

    protected static ?string $navigationLabel = 'Demonstrativos de compensação';

    protected static ?int $navigationSort = 2;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $kwh = fn ($state) => number_format((float) $state, 0, ',', '.').' kWh';

        return $schema->components([
            Section::make('Movimentação do mês')->columns(4)->schema([
                TextEntry::make('consumerUnit.name')->label('Unidade consumidora'),
                TextEntry::make('reference_month')->label('Referência')->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('F/Y'))),
                TextEntry::make('expiration_cycle')->label('Expira em')->placeholder('—'),
                TextEntry::make('balance_previous_kwh')->label('Saldo anterior')->formatStateUsing($kwh),
                TextEntry::make('injected_kwh')->label('Injetado (+)')->formatStateUsing($kwh),
                TextEntry::make('received_kwh')->label('Recebido (+)')->formatStateUsing($kwh),
                TextEntry::make('compensated_kwh')->label('Compensado (−)')->formatStateUsing($kwh),
                TextEntry::make('transferred_kwh')->label('Transferido (−)')->formatStateUsing($kwh),
                TextEntry::make('expired_kwh')->label('Expirado (−)')->formatStateUsing($kwh),
                TextEntry::make('balance_available_kwh')->label('Saldo disponível')->formatStateUsing($kwh)->weight('bold'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        $kwh = fn (TextColumn $c) => $c->numeric(0, ',', '.')->alignEnd();

        return $table
            ->columns([
                TextColumn::make('reference_month')->label('Referência')->sortable()
                    ->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('M/Y'))),
                TextColumn::make('consumerUnit.name')->label('Unidade')->sortable(),
                $kwh(TextColumn::make('balance_previous_kwh')->label('Saldo anterior')),
                $kwh(TextColumn::make('injected_kwh')->label('Injetado')),
                $kwh(TextColumn::make('received_kwh')->label('Recebido')),
                $kwh(TextColumn::make('compensated_kwh')->label('Compensado')),
                $kwh(TextColumn::make('transferred_kwh')->label('Transferido')),
                $kwh(TextColumn::make('balance_available_kwh')->label('Saldo')->weight('bold')),
            ])
            ->filters([
                SelectFilter::make('consumer_unit_id')->label('Unidade')
                    ->options(fn () => ConsumerUnit::orderBy('name')->pluck('name', 'id')),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([ViewAction::make(), ViewOriginalAction::make()]);
    }

    public static function getRelations(): array
    {
        return [TransfersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEnergyStatements::route('/'),
            'view' => ViewEnergyStatement::route('/{record}'),
        ];
    }
}
