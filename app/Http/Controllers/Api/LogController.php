<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Registro de atividades (acessos, visualizações e alterações), somente leitura. */
class LogController extends Controller
{
    private const PER_PAGE = 30;

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', ActivityLog::class), 403);

        $filters = $request->validate([
            'type' => ['nullable', 'in:'.implode(',', array_keys(ActivityLog::TYPE_LABELS))],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'search' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $page = ActivityLog::query()
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('log_name', $type))
            ->when($filters['user_id'] ?? null, fn ($q, $id) => $q->where('causer_type', (new User)->getMorphClass())->where('causer_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', $from.' 00:00:00'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<=', $to.' 23:59:59'))
            ->when($filters['search'] ?? null, fn ($q, $term) => $q->where(fn ($w) => $w
                ->where('description', 'like', '%'.$term.'%')
                ->orWhere('causer_name', 'like', '%'.$term.'%')))
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => collect($page->items())->map(fn (ActivityLog $log) => $this->present($log))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
            'users' => ActivityLog::query()->where('causer_type', (new User)->getMorphClass())->whereNotNull('causer_id')
                ->select('causer_id', 'causer_name')->distinct()->orderBy('causer_name')
                ->get()->map(fn (ActivityLog $l) => ['id' => $l->causer_id, 'name' => $l->causer_name])->values(),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(ActivityLog $log): array
    {
        $properties = $log->properties?->toArray() ?? [];
        $new = $properties['attributes'] ?? [];
        $old = $properties['old'] ?? [];

        // alteração: campo => [antes, depois]
        $changes = $log->event === 'updated' ? collect($new)->map(fn ($value, $field) => [$old[$field] ?? null, $value])->all() : [];

        return [
            'id' => $log->id,
            'type' => $log->log_name,
            'action' => $log->event,
            'description' => $log->description,
            'user_id' => $log->causer_id,
            'user_name' => $log->causer_name,
            'subject_type' => $log->subject_type ? class_basename($log->subject_type) : null,
            'subject_id' => $log->subject_id,
            'changes' => $changes,
            'added' => $properties['added'] ?? [],
            'removed' => $properties['removed'] ?? [],
            'context' => collect($properties)->except(['attributes', 'old', 'added', 'removed'])->all(),
            'channel' => $log->channel,
            'ip' => $log->ip,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
