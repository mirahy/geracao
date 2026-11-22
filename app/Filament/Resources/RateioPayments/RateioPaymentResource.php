<?php

namespace App\Filament\Resources\RateioPayments;

use App\Filament\Resources\RateioPayments\Pages\ManageRateioPayments;
use App\Models\ConsumerUnit;
use App\Models\RateioPayment;
use App\Services\Rateio\PaymentReceiptReader;
use App\Services\Rateio\RateioLedger;
use App\Support\MonthOptions;
use App\Support\UnitOptions;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Arr;
use UnitEnum;

class RateioPaymentResource extends Resource
{
    protected static ?string $model = RateioPayment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static string|UnitEnum|null $navigationGroup = 'Cadastros';

    protected static ?string $modelLabel = 'pagamento do rateio';

    protected static ?string $pluralModelLabel = 'pagamentos do rateio';

    protected static ?string $navigationLabel = 'Pagamentos do rateio';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        $months = MonthOptions::recent(60, 1);

        return $schema->components([
            FileUpload::make('file_path')->label('Comprovante (PDF ou imagem)')
                ->disk('local')->directory('rateio-comprovantes')->visibility('private')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp'])
                ->maxSize(5120)
                ->storeFileNamesIn('file_name')
                ->downloadable()
                ->live()
                ->afterStateUpdated(fn ($state, Set $set, Get $get) => self::fillFromReceipt($state, $set, $get))
                ->helperText('Opcional. Em PDF, o valor, a data, o pagador e o código do Pix são preenchidos automaticamente; em imagem, preencha os campos abaixo.')
                ->columnSpanFull(),
            Select::make('consumer_unit_id')->label('Unidade beneficiária')
                ->options(fn () => UnitOptions::editableReceiverOptions())
                ->searchable()->required()->live()
                ->afterStateUpdated(function ($state, Set $set, Get $get) {
                    if ($state && ! $get('reference_month') && ($month = app(RateioLedger::class)->oldestOpenMonth((int) $state))) {
                        $set('reference_month', $month.'-01');
                    }
                }),
            Select::make('reference_month')->label('Mês do rateio')->options($months)->searchable()->required()
                ->formatStateUsing(fn ($state) => $state ? Carbon::parse($state)->format('Y-m-d') : null)
                ->helperText('Mês da cobrança que este pagamento quita. Vários pagamentos podem ser lançados no mesmo mês.'),
            TextInput::make('amount')->label('Valor pago')->numeric()->minValue(0.01)->prefix('R$')->required(),
            DateTimePicker::make('paid_at')->label('Pago em')->seconds(false)->default(now()),
            TextInput::make('payer_name')->label('Pagador')->maxLength(255),
            TextInput::make('transaction_id')->label('Código da transação (Pix)')->maxLength(100)
                ->unique(RateioPayment::class, 'transaction_id', ignoreRecord: true)
                ->validationMessages(['unique' => 'Este comprovante já foi lançado.'])
                ->helperText('Impede lançar o mesmo comprovante duas vezes. Se um comprovante quita mais de um mês, deixe em branco nos demais lançamentos.'),
            Textarea::make('notes')->label('Observações')->rows(2)->columnSpanFull(),
        ])->columns(2);
    }

    /** Lê o comprovante enviado e preenche o que for possível; o usuário confere antes de salvar. */
    private static function fillFromReceipt(mixed $state, Set $set, Get $get): void
    {
        $file = is_array($state) ? Arr::first($state) : $state;
        if (! is_object($file) || ! method_exists($file, 'getRealPath')) {
            return;
        }

        $data = app(PaymentReceiptReader::class)->read($file->getRealPath());
        if ($data === null) {
            Notification::make()->title('Comprovante anexado')->body('Não foi possível ler o texto do arquivo (imagem ou PDF escaneado). Preencha os dados abaixo.')->warning()->send();

            return;
        }

        $set('amount', $data['amount']);
        $set('paid_at', $data['paid_at']?->format('Y-m-d H:i:s'));
        $set('transaction_id', $data['transaction_id']);
        $set('payer_name', $data['payer_name']);

        // Sugere a unidade pelo pagador de lançamentos anteriores
        if ($data['payer_name'] && ! $get('consumer_unit_id')) {
            $unitId = RateioPayment::where('payer_name', $data['payer_name'])->latest('id')->value('consumer_unit_id');
            if ($unitId) {
                $set('consumer_unit_id', $unitId);
                if (! $get('reference_month') && ($month = app(RateioLedger::class)->oldestOpenMonth((int) $unitId))) {
                    $set('reference_month', $month.'-01');
                }
            }
        }

        $missing = array_keys(array_filter($data, fn ($v) => $v === null));
        $duplicate = $data['transaction_id'] && RateioPayment::where('transaction_id', $data['transaction_id'])->exists();

        Notification::make()
            ->title($duplicate ? 'Comprovante já lançado' : 'Comprovante lido')
            ->body($duplicate ? 'Já existe um pagamento com este código de transação.' : 'Confira os dados antes de salvar'.($missing ? ' e complete o que faltou.' : '.'))
            ->{$duplicate ? 'danger' : 'success'}()
            ->send();
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('consumerUnit.name')->label('Unidade')->searchable(),
                TextColumn::make('reference_month')->label('Mês do rateio')
                    ->formatStateUsing(fn ($state) => ucfirst($state->translatedFormat('M/Y')))->sortable(),
                TextColumn::make('amount')->label('Valor pago')->money('BRL', locale: 'pt_BR')->alignEnd()->summarize(
                    Sum::make()->money('BRL', locale: 'pt_BR')->label('Total')
                ),
                TextColumn::make('paid_at')->label('Pago em')->dateTime('d/m/Y H:i')->sortable()->placeholder('—'),
                TextColumn::make('payer_name')->label('Pagador')->placeholder('—')->searchable(),
                TextColumn::make('transaction_id')->label('Transação')->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('file_name')->label('Comprovante')->placeholder('sem arquivo')
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->url(fn (RateioPayment $record) => $record->hasFile() ? route('filament.admin.rateio-payments.file', $record) : null, shouldOpenInNewTab: true)
                    ->limit(24),
            ])
            ->filters([
                SelectFilter::make('consumer_unit_id')->label('Unidade')->options(fn () => ConsumerUnit::where('role', ConsumerUnit::ROLE_RECEIVER)->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('reference_month')->label('Mês do rateio')
                    ->options(fn () => RateioPayment::query()->orderByDesc('reference_month')->pluck('reference_month')
                        ->mapWithKeys(fn ($m) => [$m->format('Y-m-d') => ucfirst($m->translatedFormat('F/Y'))])->all()),
            ])
            ->defaultSort('paid_at', 'desc')
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRateioPayments::route('/')];
    }
}
