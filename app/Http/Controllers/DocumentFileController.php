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
        abort_unless($document->hasFile(), 404, 'Arquivo original não disponível.');

        return Storage::disk('local')->response($document->path, $document->original_name, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('"', '', $document->original_name).'"',
        ]);
    }
}
