<?php

namespace App\Filament\Resources\EnergyStatements\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TransfersRelationManager extends RelationManager
{
    protected static string $relationship = 'transfers';

    protected static ?string $title = 'Transferências do mês';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('target.name')->label('UC de destino'),
                TextColumn::make('target.formatted_uc')->label('Nº da UC'),
                TextColumn::make('percentage')->label('Percentual')->suffix('%')->alignEnd(),
                TextColumn::make('kwh')->label('Energia (kWh)')->numeric(0, ',', '.')->alignEnd()->weight('bold'),
                TextColumn::make('cycle_month')->label('Ciclo')->formatStateUsing(fn ($state) => $state?->format('m/Y'))->placeholder('—'),
            ])
            ->paginated(false);
    }
}
