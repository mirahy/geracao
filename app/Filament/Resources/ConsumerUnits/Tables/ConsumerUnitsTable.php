<?php

namespace App\Filament\Resources\ConsumerUnits\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class ConsumerUnitsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Nome')->searchable()->weight('bold'),
                TextColumn::make('role')->label('Papel')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'generator' ? 'Geradora' : 'Beneficiária')
                    ->color(fn ($state) => $state === 'generator' ? 'warning' : 'info'),
                TextColumn::make('formatted_uc')->label('UC'),
                TextColumn::make('code')->label('Instalação')->searchable()->placeholder('—'),
                TextColumn::make('city')->label('Cidade')->searchable()->toggleable(),
                TextColumn::make('address')->label('Endereço')->limit(40)->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('active')->label('Ativa')->boolean(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])])
            ->defaultSort('role');
    }
}
