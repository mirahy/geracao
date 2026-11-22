<?php

namespace App\Filament\Resources\RateioPayments\Pages;

use App\Filament\Resources\RateioPayments\RateioPaymentResource;
use App\Filament\Resources\RateioPayments\Widgets\RateioSituationTable;
use App\Models\RateioPayment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageRateioPayments extends ManageRecords
{
    protected static string $resource = RateioPaymentResource::class;

    protected static ?string $title = 'Pagamentos do rateio';

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Lançar pagamento / enviar comprovante')
                ->modalHeading('Lançar pagamento do rateio')
                ->mutateDataUsing(function (array $data): array {
                    $data['source'] = ! empty($data['file_path']) ? RateioPayment::SOURCE_RECEIPT : RateioPayment::SOURCE_MANUAL;
                    $data['created_by'] = auth()->id();

                    return $data;
                }),
        ];
    }

    protected function getFooterWidgets(): array
    {
        return [RateioSituationTable::class];
    }
}
