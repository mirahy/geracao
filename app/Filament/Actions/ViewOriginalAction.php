<?php

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;

class ViewOriginalAction
{
    /** Abre o PDF original do registro (fatura ou demonstrativo) em um modal. */
    public static function make(string $name = 'verOriginal'): Action
    {
        return Action::make($name)
            ->label('Ver original')
            ->icon(Heroicon::OutlinedDocumentMagnifyingGlass)
            ->color('gray')
            ->visible(fn (Model $record): bool => (bool) $record->document?->hasFile())
            ->modalHeading(fn (Model $record): string => $record->document?->original_name ?? 'Documento original')
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Fechar')
            ->modalContent(fn (Model $record) => view('filament.pdf-viewer', [
                'url' => route('filament.admin.documents.file', $record->document),
            ]));
    }
}
