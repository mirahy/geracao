<?php

namespace App\Filament\Resources\ImportedDocuments\Pages;

use App\Filament\Actions\ImportDocumentsAction;
use App\Filament\Resources\ImportedDocuments\ImportedDocumentResource;
use Filament\Resources\Pages\ListRecords;

class ListImportedDocuments extends ListRecords
{
    protected static string $resource = ImportedDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [ImportDocumentsAction::make()];
    }
}
