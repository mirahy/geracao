<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use App\Models\ConsumerUnit;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class UnitsSummaryTable extends TableWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 8;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        $summary = $this->energy()->unitsSummary()->keyBy(fn ($row) => $row['unit']->id);
        $kwh = fn (string $key) => fn (ConsumerUnit $r) => number_format($summary[$r->id][$key] ?? 0, 0, ',', '.');
        $brl = fn (string $key) => fn (ConsumerUnit $r) => 'R$ '.number_format($summary[$r->id][$key] ?? 0, 2, ',', '.');

        return $table
            ->heading('Resumo por unidade consumidora (período selecionado)')
            ->query(fn (): Builder => ConsumerUnit::query()
                ->when($this->record?->getKey() ?? ($this->pageFilters['unit_id'] ?? null),
                    fn (Builder $q, $id) => $q->where('id', $id))
                ->orderBy('role')->orderBy('name'))
            ->paginated(false)
            ->columns([
                TextColumn::make('name')->label('Unidade')->description(fn (ConsumerUnit $r) => $r->code ? "Instalação {$r->code}" : $r->formatted_uc),
                TextColumn::make('role')->label('Papel')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'generator' ? 'Geradora' : 'Beneficiária')
                    ->color(fn ($state) => $state === 'generator' ? 'warning' : 'info'),
                TextColumn::make('generated')->label('Geração (kWh)')->alignEnd()->state($kwh('generated')),
                TextColumn::make('self_consumed')->label('Autoconsumo (kWh)')->alignEnd()->state($kwh('self_consumed')),
                TextColumn::make('consumption')->label('Consumo da rede (kWh)')->alignEnd()->state($kwh('consumption')),
                TextColumn::make('total_consumption')->label('Consumo total (kWh)')->alignEnd()->state($kwh('total_consumption')),
                TextColumn::make('compensated')->label('Compensado (kWh)')->alignEnd()->state($kwh('compensated')),
                TextColumn::make('sent')->label('Transferido (kWh)')->alignEnd()->state($kwh('sent')),
                TextColumn::make('received')->label('Recebido (kWh)')->alignEnd()->state($kwh('received')),
                TextColumn::make('cost')->label('Custo')->alignEnd()->state($brl('cost')),
                TextColumn::make('installments')->label('Custo das placas')->alignEnd()->state($brl('installments')),
                TextColumn::make('savings')->label('Economia')->alignEnd()->state($brl('savings'))->color('success'),
                TextColumn::make('balance')->label('Saldo (kWh)')->alignEnd()
                    ->state(fn (ConsumerUnit $r) => isset($summary[$r->id]['balance']) ? number_format($summary[$r->id]['balance'], 0, ',', '.') : '—'),
            ]);
    }
}
