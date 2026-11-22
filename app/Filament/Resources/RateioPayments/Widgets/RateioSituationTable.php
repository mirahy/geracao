<?php

namespace App\Filament\Resources\RateioPayments\Widgets;

use App\Services\Rateio\RateioLedger;
use Carbon\CarbonImmutable;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/** Situação do rateio por unidade beneficiária e mês: devido × pago × saldo. */
class RateioSituationTable extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public const STATUS_LABELS = [
        RateioLedger::PENDING => 'Pendente',
        RateioLedger::PARTIAL => 'Pago em parte',
        RateioLedger::PAID => 'Pago',
        RateioLedger::OVERPAID => 'Pago a maior',
        RateioLedger::WITHOUT_CHARGE => 'Sem rateio no mês',
    ];

    public const STATUS_COLORS = [
        RateioLedger::PENDING => 'danger',
        RateioLedger::PARTIAL => 'warning',
        RateioLedger::PAID => 'success',
        RateioLedger::OVERPAID => 'info',
        RateioLedger::WITHOUT_CHARGE => 'gray',
    ];

    public function table(Table $table): Table
    {
        $brl = fn (string $key) => fn (array $record) => 'R$ '.number_format($record[$key], 2, ',', '.');

        return $table
            ->heading('Situação do rateio por mês')
            ->description('Rateio devido por unidade beneficiária (parcela das placas proporcional aos créditos recebidos) comparado com os pagamentos lançados.')
            ->records(fn (): array => app(RateioLedger::class)
                ->rows(auth()->user()?->viewableUnitIds())
                ->take(60)
                ->keyBy(fn (array $row) => $row['unit_id'].'|'.$row['month'])
                ->all())
            ->paginated(false)
            ->emptyStateHeading('Nenhum rateio calculado ainda')
            ->columns([
                TextColumn::make('unit')->label('Unidade'),
                TextColumn::make('month')->label('Mês do rateio')
                    ->formatStateUsing(fn (string $state) => ucfirst(CarbonImmutable::createFromFormat('Y-m-d', $state.'-01')->translatedFormat('F/Y'))),
                TextColumn::make('due')->label('Rateio devido')->alignEnd()->state($brl('due')),
                TextColumn::make('paid')->label('Pago')->alignEnd()->state($brl('paid')),
                TextColumn::make('balance')->label('Saldo a pagar')->alignEnd()
                    ->state(fn (array $record) => ($record['balance'] < -0.004 ? '- ' : '').'R$ '.number_format(abs($record['balance']), 2, ',', '.'))
                    ->color(fn (array $record) => $record['balance'] > 0.004 ? 'danger' : 'success'),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn (string $state) => self::STATUS_LABELS[$state] ?? $state)
                    ->color(fn (string $state) => self::STATUS_COLORS[$state] ?? 'gray'),
                TextColumn::make('payments')->label('Pagamentos')->alignCenter(),
            ]);
    }
}
