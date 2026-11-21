<?php

namespace App\Filament\Widgets;

use App\Filament\Widgets\Concerns\UsesEnergyDashboard;
use App\Models\ConsumerUnit;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class EnergyStatsOverview extends StatsOverviewWidget
{
    use UsesEnergyDashboard;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    protected function getStats(): array
    {
        $t = $this->energy()->totals();
        $kwh = fn (?float $v) => number_format($v ?? 0, 0, ',', '.').' kWh';
        $brl = fn (float $v) => 'R$ '.number_format($v, 2, ',', '.');

        $unitCost = $this->energy()->scopeCostPerKwh();
        $unitCostStat = $unitCost === null ? [] : [
            Stat::make('Custo do kWh da unidade', $unitCost['rate'] !== null ? 'R$ '.number_format($unitCost['rate'], 4, ',', '.') : '—')
                ->description(collect($unitCost['parts'])->map(fn ($v, $k) => "{$k} {$brl($v)}")->implode(' + ')
                    .' = '.$brl($unitCost['amount']).' ÷ '.number_format($unitCost['kwh'], 0, ',', '.').' kWh ('.$unitCost['per'].')')
                ->icon(Heroicon::OutlinedCalculator)->color('warning'),
        ];

        $isReceiverScope = $this->energy()->units()->count() === 1
            && $this->energy()->units()->first()->role === ConsumerUnit::ROLE_RECEIVER;
        $solarCostStat = $isReceiverScope ? [] : [
            Stat::make('Custo do kWh das placas', $t['solar_cost_per_kwh'] !== null ? 'R$ '.number_format($t['solar_cost_per_kwh'], 4, ',', '.') : '—')
                ->description('parcelas ÷ geração dos meses do período filtrado')
                ->icon(Heroicon::OutlinedCalculator)->color('gray'),
        ];

        return [
            ...$unitCostStat,
            ...$solarCostStat,
            Stat::make('Geração das placas', $kwh($t['generated']))
                ->description($t['generated'] > 0 ? 'informada manualmente' : 'cadastre a geração mensal')
                ->icon(Heroicon::OutlinedSun)->color('warning'),
            Stat::make('Autoconsumo das placas', $kwh($t['self_consumed']))
                ->description('gasto direto da placa, antes de injetar (geração − injetado)')
                ->icon(Heroicon::OutlinedHome)->color('primary'),
            Stat::make('Injetado na rede', $kwh($t['injected']))
                ->description('energia enviada à distribuidora')
                ->icon(Heroicon::OutlinedArrowUpCircle)->color('success'),
            Stat::make('Transferido a outras UCs', $kwh($t['transferred']))
                ->description('créditos distribuídos')
                ->icon(Heroicon::OutlinedArrowsRightLeft)->color('info'),
            Stat::make('Consumo da rede', $kwh($t['consumption']))
                ->description('consumo total (rede + autoconsumo): '.$kwh($t['total_consumption']))
                ->icon(Heroicon::OutlinedBolt)->color('gray'),
            Stat::make('Custo total', $brl($t['total_cost']))
                ->description('faturas '.$brl($t['cost']).' + placas (após rateio) '.$brl($t['installments']))
                ->icon(Heroicon::OutlinedBanknotes)->color('danger'),
            Stat::make('Economia total', $brl($t['total_savings']))
                ->description('compensação '.$brl($t['savings']).' + autoconsumo '.$brl($t['self_savings']))
                ->icon(Heroicon::OutlinedCurrencyDollar)->color('success'),
            Stat::make('Rateio cobrado das beneficiárias', $brl($t['share_charged']))
                ->description('abatido da parcela da UC geradora: '.$brl($t['share_abated']))
                ->icon(Heroicon::OutlinedReceiptPercent)->color('info'),
            Stat::make('Resultado líquido', $brl($t['net_result']))
                ->description('economia total − parcela das placas')
                ->icon(Heroicon::OutlinedScale)->color($t['net_result'] >= 0 ? 'success' : 'danger'),
            Stat::make('Saldo de créditos', $kwh($t['balance']))
                ->description('recebido − compensado (acumulado), somando as UCs')
                ->icon(Heroicon::OutlinedBattery100)->color('primary'),
            Stat::make('Compensado nas faturas', $kwh($t['compensated']))
                ->description('kWh abatidos do consumo')
                ->icon(Heroicon::OutlinedCheckCircle)->color('success'),
        ];
    }
}
