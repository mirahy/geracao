<?php

namespace App\Filament\Resources\ConsumerUnits\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConsumerUnitForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Identificação')->columns(2)->schema([
                TextInput::make('name')->label('Nome de exibição')->required()
                    ->helperText('Ex.: "Casa Navirai", "Apartamento Campo Grande".'),
                Select::make('role')->label('Papel')->required()->default('receiver')
                    ->options(['generator' => 'Geradora (com placas solares)', 'receiver' => 'Beneficiária (recebe créditos)']),
                TextInput::make('uc_number')->label('Número da UC (somente dígitos)')->required()->unique(ignoreRecord: true)
                    ->helperText('Número impresso na fatura, ex.: 917.358.051-98 → 91735805198.')
                    ->dehydrateStateUsing(fn ($state) => preg_replace('/\D/', '', (string) $state)),
                TextInput::make('code')->label('Código da instalação')->unique(ignoreRecord: true)->placeholder('2496572-5'),
                Toggle::make('active')->label('Ativa')->default(true),
            ]),
            Section::make('Dados cadastrais')->columns(2)->schema([
                TextInput::make('holder_name')->label('Titular'),
                TextInput::make('classification')->label('Classificação'),
                TextInput::make('address')->label('Endereço')->columnSpanFull(),
                TextInput::make('neighborhood')->label('Bairro'),
                TextInput::make('city')->label('Cidade'),
                TextInput::make('meter_number')->label('Medidor'),
                Textarea::make('notes')->label('Observações')->columnSpanFull(),
            ]),
        ]);
    }
}
