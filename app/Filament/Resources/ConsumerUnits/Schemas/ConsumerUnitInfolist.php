<?php

namespace App\Filament\Resources\ConsumerUnits\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConsumerUnitInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Unidade consumidora')->columns(3)->schema([
                TextEntry::make('name')->label('Nome'),
                TextEntry::make('role')->label('Papel')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'generator' ? 'Geradora' : 'Beneficiária')
                    ->color(fn ($state) => $state === 'generator' ? 'warning' : 'info'),
                IconEntry::make('active')->label('Ativa')->boolean(),
                TextEntry::make('formatted_uc')->label('Número da UC'),
                TextEntry::make('code')->label('Instalação')->placeholder('—'),
                TextEntry::make('meter_number')->label('Medidor')->placeholder('—'),
                TextEntry::make('holder_name')->label('Titular')->placeholder('—'),
                TextEntry::make('address')->label('Endereço')->placeholder('—')->columnSpan(2),
                TextEntry::make('city')->label('Cidade')->placeholder('—'),
            ]),
        ]);
    }
}
