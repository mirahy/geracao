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
                            ->allowHtml()
                            ->options(fn () => ConsumerUnit::orderBy('role')->orderBy('name')->get()
                                ->mapWithKeys(fn (ConsumerUnit $unit) => [$unit->id => self::optionLabel($unit)]))
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

    /** Nome da UC seguido de um selo "Geradora" ou "Beneficiária". */
    private static function optionLabel(ConsumerUnit $unit): string
    {
        $generator = $unit->role === ConsumerUnit::ROLE_GENERATOR;
        [$text, $color] = $generator ? ['Geradora', '245, 158, 11'] : ['Beneficiária', '59, 130, 246'];

        return e($unit->name)
            ." <span style='display:inline-block;margin-left:.5rem;padding:0 .5rem;border-radius:.375rem;font-size:.75rem;font-weight:500;line-height:1.25rem;"
            ."color:rgb({$color});background:rgba({$color}, .15);box-shadow:inset 0 0 0 1px rgba({$color}, .35)'>{$text}</span>";
    }
}
