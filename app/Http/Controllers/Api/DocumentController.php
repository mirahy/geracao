<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ImportedDocument;
use App\Services\Documents\DocumentImporter;
use App\Services\Notifications\ImportNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Importação de faturas e demonstrativos (PDF) e histórico das importações. */
class DocumentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('documentos.ver'), 403);

        $filters = $request->validate(['unit_id' => ['nullable', 'integer'], 'status' => ['nullable', 'in:success,failed']]);

        $documents = ImportedDocument::with('consumerUnit')
            ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('consumer_unit_id', $id))
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->latest('id')
            ->limit(120)
            ->get()
            ->map(fn (ImportedDocument $d) => $this->present($d))
            ->values();

        return response()->json(['data' => $documents]);
    }

    /** Importa vários PDFs de uma vez; arquivos repetidos (mesmo hash) são ignorados. */
    public function import(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('documentos.importar'), 403);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:40'],
            'files.*' => ['file', 'max:20480', 'mimetypes:application/pdf'],
        ]);

        $importer = app(DocumentImporter::class);
        $imported = collect();
        $duplicates = 0;
        $failures = [];

        foreach ($request->file('files') as $file) {
            $document = $importer->import($file->getRealPath(), $file->getClientOriginalName());

            if ($document->wasDuplicate) {
                $duplicates++;
            } elseif ($document->status === 'success') {
                $imported->push($document);
            } else {
                $failures[] = ['file' => $file->getClientOriginalName(), 'message' => $document->message];
            }
        }

        ImportNotifier::notify($imported);

        return response()->json(['data' => [
            'imported' => $imported->count(),
            'duplicates' => $duplicates,
            'failures' => $failures,
            'documents' => $imported->map(fn (ImportedDocument $d) => $this->present($d->loadMissing('consumerUnit')))->values(),
        ]], 201);
    }

    public function destroy(Request $request, ImportedDocument $document): JsonResponse
    {
        abort_unless($request->user()->can('delete', $document), 403);

        $document->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(ImportedDocument $d): array
    {
        return [
            'id' => $d->id,
            'original_name' => $d->original_name,
            'type' => $d->type,
            'status' => $d->status,
            'message' => $d->message,
            'consumer_unit_id' => $d->consumer_unit_id,
            'unit_name' => $d->consumerUnit?->name,
            'reference_month' => $d->reference_month?->format('Y-m-d'),
            'created_at' => $d->created_at?->toIso8601String(),
        ];
    }
}
