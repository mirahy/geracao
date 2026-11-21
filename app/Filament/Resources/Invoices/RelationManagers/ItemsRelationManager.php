<?php

namespace App\Filament\Resources\Invoices\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Itens da fatura';

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('description')->label('Descrição')->wrap(),
                TextColumn::make('quantity')->label('Quantidade')->numeric(2, ',', '.')->alignEnd()->placeholder('—'),
                TextColumn::make('unit_price')->label('Preço unit.')->numeric(6, ',', '.')->alignEnd()->placeholder('—'),
                TextColumn::make('amount')->label('Valor (R$)')->numeric(2, ',', '.')->alignEnd()->weight('bold'),
                TextColumn::make('icms_amount')->label('ICMS (R$)')->numeric(2, ',', '.')->alignEnd()->placeholder('—'),
            ])
            ->paginated(false);
    }
}
