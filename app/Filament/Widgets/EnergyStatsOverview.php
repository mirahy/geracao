<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EnergyStatsOverview extends StatsOverviewWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 1;

    protected int | string | array $columnSpan = 'full';

    protected function getStats(): array
    {
        $t = $this->energy()->totals();
        $kwh = fn (?float $v) => number_format($v ?? 0, 0, ',', '.').' kWh';
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        return [
            Stat::make('Geração das placas', $kwh($t['generated']))
                ->description($t['generated'] > 0 ? 'informada manualmente' : 'cadastre a geração mensal')
                ->icon(Heroicon::OutlinedSun)->color('warning'),
            Stat::make('Injetado na rede', $kwh($t['injected']))
                ->description('energia enviada à distribuidora')
                ->icon(Heroicon::OutlinedArrowUpCircle)->color('success'),
            Stat::make('Transferido a outras UCs', $kwh($t['transferred']))
                ->description('créditos distribuídos')
                ->icon(Heroicon::OutlinedArrowsRightLeft)->color('info'),
            Stat::make('Consumo da rede', $kwh($t['consumption']))
                ->description('medido nas UCs')
                ->icon(Heroicon::OutlinedBolt)->color('gray'),
            Stat::make('Custo das faturas', $brl($t['cost']))
                ->description('total faturado no período')
                ->icon(Heroicon::OutlinedBanknotes)->color('danger'),
            Stat::make('Economia com compensação', $brl($t['savings']))
                ->description('crédito compensado − ajuste Lei 14.300')
                ->icon(Heroicon::OutlinedCurrencyDollar)->color('success'),
            Stat::make('Saldo de créditos', $kwh($t['balance']))
                ->description('somatório do último saldo de cada UC')
                ->icon(Heroicon::OutlinedBattery100)->color('primary'),
            Stat::make('Compensado nas faturas', $kwh($t['compensated']))
                ->description('kWh abatidos do consumo')
                ->icon(Heroicon::OutlinedCheckCircle)->color('success'),
        ];
    }
}
