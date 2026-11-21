<?php

namespace App\Filament\Support;

use App\Models\ConsumerUnit;
use App\Services\Dashboard\EnergyDashboard;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/** Filtros (unidade e período) compartilhados pelo painel e pela página de cada unidade. */
class EnergyFilters
{
    public static function configure(Schema $schema, bool $withUnit = true): Schema
    {
        $months = EnergyDashboard::availableMonths();

        return $schema->components([
            Section::make()
                ->icon(Heroicon::OutlinedFunnel)
                ->schema([
                    ...($withUnit ? [
                        Select::make('unit_id')
                            ->label('Unidade consumidora')
                            ->placeholder('Todas as unidades')
                            ->options(fn () => ConsumerUnit::orderBy('name')->pluck('name', 'id'))
                            ->searchable(),
                    ] : []),
                    Select::make('from')
                        ->label('Mês inicial')
                        ->placeholder('Últimos 12 meses')
                        ->options($months),
                    Select::make('to')
                        ->label('Mês final')
                        ->placeholder('Mais recente')
                        ->options($months),
                ])
                ->columns($withUnit ? 3 : 2)
                ->columnSpanFull(),
        ]);
    }
}
