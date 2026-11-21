<?php

namespace App\Filament\Resources\ImportedDocuments;

use App\Filament\Resources\ImportedDocuments\Pages\ListImportedDocuments;
use App\Models\ImportedDocument;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ImportedDocumentResource extends Resource
{
    protected static ?string $model = ImportedDocument::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static string|UnitEnum|null $navigationGroup = 'Documentos';

    protected static ?string $modelLabel = 'importação';

    protected static ?string $pluralModelLabel = 'importações';

    protected static ?string $navigationLabel = 'Importações (PDF)';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('created_at')->label('Importado em')->dateTime('d/m/Y H:i')->sortable(),
                TextColumn::make('original_name')->label('Arquivo')->searchable()->wrap(),
                TextColumn::make('type')->label('Tipo')->badge()
                    ->formatStateUsing(fn ($state) => match ($state) {
                        'invoice' => 'Fatura',
                        'statement' => 'Demonstrativo',
                        default => '—',
                    }),
                TextColumn::make('consumerUnit.name')->label('Unidade')->placeholder('—'),
                TextColumn::make('reference_month')->label('Referência')->formatStateUsing(fn ($state) => $state?->format('m/Y'))->placeholder('—'),
                TextColumn::make('status')->label('Situação')->badge()
                    ->formatStateUsing(fn ($state) => $state === 'success' ? 'Importado' : 'Falhou')
                    ->color(fn ($state) => $state === 'success' ? 'success' : 'danger'),
                TextColumn::make('message')->label('Mensagem')->limit(60)->tooltip(fn ($record) => $record->message),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('abrirPdf')->label('Ver original')->icon(Heroicon::OutlinedDocumentMagnifyingGlass)->color('gray')
                    ->visible(fn (ImportedDocument $record): bool => $record->hasFile())
                    ->url(fn (ImportedDocument $record): string => route('filament.admin.documents.file', $record), shouldOpenInNewTab: true),
                DeleteAction::make(),
            ]);
    }

    /** Telas ligadas à unidade geradora: ocultas para quem só alcança unidades beneficiárias. */
    public static function canViewAny(): bool
    {
        return parent::canViewAny() && (auth()->user()?->hasGeneratorAccess() ?? false);
    }

    public static function getPages(): array
    {
        return ['index' => ListImportedDocuments::route('/')];
    }
}
