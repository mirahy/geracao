<?php

namespace App\Filament\Resources\Invoices\Pages;

use App\Filament\Actions\ViewOriginalAction;
use App\Filament\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [ViewOriginalAction::make()];
    }
}
