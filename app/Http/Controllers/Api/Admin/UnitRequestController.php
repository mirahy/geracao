<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnitRequest;
use App\Services\UnitRequests\UnitRequestService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Aprovação e recusa dos pedidos de acesso a unidades. */
class UnitRequestController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', UnitRequest::class), 403);

        $status = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(UnitRequest::STATUS_LABELS))]])['status'] ?? null;

        $rows = UnitRequest::with(['user', 'consumerUnit', 'decider'])
            ->when($status, fn ($q, $s) => $q->where('status', $s))
            ->latest('id')->limit(120)->get()
            ->map(fn (UnitRequest $r) => $this->present($r))->values();

        return response()->json(['data' => $rows]);
    }

    public function approve(Request $request, UnitRequest $unitRequest): JsonResponse
    {
        abort_unless($request->user()->can('update', $unitRequest), 403);

        $data = $request->validate(['can_edit' => ['nullable', 'boolean']]);

        return $this->decide($unitRequest, fn () => app(UnitRequestService::class)->approve($unitRequest, $request->user(), (bool) ($data['can_edit'] ?? false)));
    }

    public function reject(Request $request, UnitRequest $unitRequest): JsonResponse
    {
        abort_unless($request->user()->can('update', $unitRequest), 403);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:300']]);

        return $this->decide($unitRequest, fn () => app(UnitRequestService::class)->reject($unitRequest, $request->user(), $data['note'] ?? null));
    }

    /** Aprova (somente leitura, ou com edição) ou recusa vários pedidos de uma vez; os já respondidos são ignorados. */
    public function bulk(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', UnitRequest::class), 403);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer', 'exists:unit_requests,id'],
            'action' => ['required', 'in:approve,reject'],
            'can_edit' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $service = app(UnitRequestService::class);
        $ok = $failed = 0;

        foreach (UnitRequest::whereIn('id', $data['ids'])->get() as $unitRequest) {
            try {
                $data['action'] === 'approve'
                    ? $service->approve($unitRequest, $request->user(), (bool) ($data['can_edit'] ?? false))
                    : $service->reject($unitRequest, $request->user(), $data['note'] ?? null);
                $ok++;
            } catch (DomainException) {
                $failed++;
            }
        }

        return response()->json(['data' => ['done' => $ok, 'skipped' => $failed]]);
    }

    private function decide(UnitRequest $unitRequest, callable $decision): JsonResponse
    {
        try {
            $decision();
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['request' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->present($unitRequest->fresh(['user', 'consumerUnit', 'decider']))]);
    }

    /** @return array<string, mixed> */
    private function present(UnitRequest $r): array
    {
        return [
            'id' => $r->id,
            'user_id' => $r->user_id,
            'user_name' => $r->user?->name,
            'user_email' => $r->user?->email,
            'consumer_unit_id' => $r->consumer_unit_id,
            'unit_name' => $r->consumerUnit?->name,
            'unit_uc' => $r->consumerUnit?->formatted_uc,
            'message' => $r->message,
            'status' => $r->status,
            'status_label' => UnitRequest::STATUS_LABELS[$r->status] ?? $r->status,
            'created_at' => $r->created_at?->toIso8601String(),
            'decided_by' => $r->decider?->name,
            'decision_note' => $r->decision_note,
        ];
    }
}
