<?php

namespace App\Filament\Actions;

use App\Services\Documents\DocumentImporter;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Storage;

class ImportDocumentsAction
{
    public static function make(string $name = 'importarPdfs'): Action
    {
        return Action::make($name)
            ->label('Importar PDFs')
            ->icon(Heroicon::OutlinedArrowUpTray)
            ->color('primary')
            ->visible(fn () => auth()->user()?->can('documentos.importar'))
            ->modalHeading('Importar faturas e demonstrativos')
            ->modalDescription('Envie as faturas (DANF3E) e os demonstrativos de compensação de energia injetada da Energisa. Os dados são extraídos e gravados automaticamente.')
            ->modalSubmitActionLabel('Importar')
            ->schema([
                FileUpload::make('files')
                    ->label('Arquivos PDF')
                    ->multiple()
                    ->maxFiles(40)
                    ->acceptedFileTypes(['application/pdf'])
                    ->maxSize(20480)
                    ->disk('local')
                    ->directory('imports')
                    ->visibility('private')
                    ->preserveFilenames()
                    ->appendFiles()
                    ->required(),
            ])
            ->action(function (array $data): void {
                $importer = app(DocumentImporter::class);
                $disk = Storage::disk('local');

                $ok = $duplicates = 0;
                $failures = [];

                foreach ((array) ($data['files'] ?? []) as $path) {
                    $document = $importer->import($disk->path($path), basename($path));
                    $disk->delete($path); // a cópia definitiva fica em documents/{hash}.pdf

                    if ($document->wasDuplicate) {
                        $duplicates++;
                    } elseif ($document->status === 'success') {
                        $ok++;
                    } else {
                        $failures[] = basename($path).': '.$document->message;
                    }
                }

                $body = collect([
                    $ok ? "{$ok} arquivo(s) importado(s)." : null,
                    $duplicates ? "{$duplicates} já importado(s) antes (ignorados)." : null,
                    ...array_map(fn ($f) => "Falha — {$f}", $failures),
                ])->filter()->implode("\n");

                Notification::make()
                    ->title($failures ? 'Importação concluída com avisos' : 'Importação concluída')
                    ->body($body)
                    ->color($failures ? 'warning' : 'success')
                    ->persistent((bool) $failures)
                    ->send();
            });
    }
}
