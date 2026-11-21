<?php

namespace App\Http\Controllers;

use App\Models\ImportedDocument;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentFileController extends Controller
{
    /** Exibe o PDF original importado (somente usuários autenticados no painel). */
    public function __invoke(ImportedDocument $document): StreamedResponse
    {
        $user = auth()->user();
        $allowed = $user->can('documentos.ver')
            || ($document->type === ImportedDocument::TYPE_INVOICE && $user->can('faturas.ver'))
            || ($document->type === ImportedDocument::TYPE_STATEMENT && $user->can('demonstrativos.ver'));
        abort_unless($allowed && $user->canViewUnit($document->consumer_unit_id), 403);
        abort_unless($document->hasFile(), 404, 'Arquivo original não disponível.');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->original_name).'"',
        ]);
    }
}
