<?php

namespace App\Filament\Resources\Invoices;

use App\Filament\Actions\ViewOriginalAction;
use App\Filament\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Resources\Invoices\Pages\ViewInvoice;
use App\Filament\Resources\Invoices\RelationManagers\ItemsRelationManager;
use App\Models\ConsumerUnit;
use App\Models\Invoice;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class InvoiceResource extends Resource
{
    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'Documentos';

    protected static ?string $modelLabel = 'fatura';

    protected static ?string $pluralModelLabel = 'faturas';

    protected static ?int $navigationSort = 1;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        $brl = fn ($state) => 'R$ '.number_format((float) $state, 2, ',', '.');
        $kwh = fn ($state) => number_format((float) $state, 2, ',', '.').' kWh';

        return $schema->components([
            Section::make('Fatura')->columns(4)->schema([
                TextEntry::make('consumerUnit.name')->label('Unidade'),
                TextEntry::make('reference_month')->label('Referência')->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('F/Y'))),
                TextEntry::make('invoice_number')->label('Nota fiscal')->placeholder('—'),
                TextEntry::make('total_amount')->label('Valor total')->formatStateUsing($brl)->weight('bold'),
                TextEntry::make('issue_date')->label('Emissão')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('due_date')->label('Vencimento')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('paid_at')->label('Pago em')->date('d/m/Y')->placeholder('não informado'),
                TextEntry::make('billing_days')->label('Dias faturados')->placeholder('—'),
                TextEntry::make('reading_previous_date')->label('Leitura anterior')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('reading_current_date')->label('Leitura atual')->date('d/m/Y')->placeholder('—'),
                TextEntry::make('meter_number')->label('Medidor')->placeholder('—'),
                IconEntry::make('is_contingency')->label('Emitida em contingência')->boolean(),
            ]),
            Section::make('Energia')->columns(4)->schema([
                TextEntry::make('consumption_kwh')->label('Consumo faturado')->formatStateUsing($kwh),
                TextEntry::make('compensated_kwh')->label('Compensado')->formatStateUsing($kwh),
                TextEntry::make('injected_kwh')->label('Injetado (medidor)')->formatStateUsing($kwh),
                TextEntry::make('credit_balance_kwh')->label('Saldo acumulado')->formatStateUsing($kwh),
                TextEntry::make('credit_origin')->label('Origem do crédito')->placeholder('—'),
                TextEntry::make('tariff_per_kwh')->label('Tarifa (c/ tributos)')->formatStateUsing(fn ($state) => $state ? 'R$ '.number_format((float) $state, 6, ',', '.') : '—'),
            ]),
            Section::make('Valores')->columns(4)->schema([
                TextEntry::make('energy_amount')->label('Consumo em kWh')->formatStateUsing($brl),
                TextEntry::make('compensation_credit_amount')->label('Crédito da energia injetada')->formatStateUsing($brl),
                TextEntry::make('compensation_fee_amount')->label('Ajuste GD II (Lei 14.300)')->formatStateUsing($brl),
                TextEntry::make('cip_amount')->label('Iluminação pública (CIP)')->formatStateUsing($brl),
                TextEntry::make('other_charges_amount')->label('Multa/juros/ atualização')->formatStateUsing($brl),
                TextEntry::make('savings')->label('Economia líquida')->formatStateUsing($brl)->color('success')->weight('bold'),
                TextEntry::make('amount_without_solar')->label('Sem compensação seria')->formatStateUsing($brl),
            ]),
            Section::make('Composição do valor')->columns(6)->collapsible()->schema([
                TextEntry::make('cost_distribution')->label('Distribuição')->formatStateUsing($brl),
                TextEntry::make('cost_energy')->label('Energia')->formatStateUsing($brl),
                TextEntry::make('cost_transmission')->label('Transmissão')->formatStateUsing($brl),
                TextEntry::make('cost_charges')->label('Encargos')->formatStateUsing($brl),
                TextEntry::make('cost_taxes')->label('Impostos')->formatStateUsing($brl),
                TextEntry::make('cost_other')->label('Outros')->formatStateUsing($brl),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference_month')->label('Referência')->sortable()
                    ->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('M/Y'))),
                TextColumn::make('consumerUnit.name')->label('Unidade')->searchable()->sortable(),
                TextColumn::make('consumption_kwh')->label('Consumo (kWh)')->numeric(0, ',', '.')->alignEnd(),
                TextColumn::make('compensated_kwh')->label('Compensado (kWh)')->numeric(0, ',', '.')->alignEnd(),
                TextColumn::make('total_amount')->label('Total')->money('BRL', locale: 'pt_BR')->alignEnd()->sortable()->weight('bold'),
                TextColumn::make('savings')->label('Economia')->money('BRL', locale: 'pt_BR')->alignEnd()->color('success'),
                TextColumn::make('due_date')->label('Vencimento')->date('d/m/Y')->sortable(),
                IconColumn::make('paid_at')->label('Paga')->boolean()->state(fn (Invoice $r) => $r->paid_at !== null),
            ])
            ->filters([
                SelectFilter::make('consumer_unit_id')->label('Unidade')
                    ->options(fn () => ConsumerUnit::orderBy('name')->pluck('name', 'id')),
            ])
            ->defaultSort('reference_month', 'desc')
            ->recordActions([ViewAction::make(), ViewOriginalAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListInvoices::route('/'),
            'view' => ViewInvoice::route('/{record}'),
        ];
    }
}
