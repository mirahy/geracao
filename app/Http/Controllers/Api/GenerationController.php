<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumerUnit;
use App\Models\SolarDailyProduction;
use App\Models\SolarGeneration;
use App\Services\Solar\DailyProductionSpreadsheet;
use App\Services\Solar\SolarGenerationConsolidator;
use App\Support\UnitOptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

class GenerationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('geracao.ver'), 403);

        $filters = $request->validate([
            'unit_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date_format:Y-m'],
            'to' => ['nullable', 'date_format:Y-m'],
        ]);

        $rows = SolarGeneration::with('consumerUnit')
            ->when($filters['unit_id'] ?? null, fn ($q, $id) => $q->where('consumer_unit_id', $id))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('reference_month', '>=', $from.'-01'))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('reference_month', '<=', $to.'-01'))
            ->orderByDesc('reference_month')
            ->limit(120)
            ->get()
            ->map(fn (SolarGeneration $g) => $this->present($g))
            ->values();

        return response()->json(['data' => $rows]);
    }

    /** Informa (ou atualiza) a geração total do mês de uma UC geradora. */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('geracao.editar'), 403);

        $data = $request->validate([
            'consumer_unit_id' => ['required', 'integer', 'exists:consumer_units,id'],
            'reference_month' => ['required', 'date_format:Y-m-d'],
            'generated_kwh' => ['required', 'numeric', 'min:0'],
        ]);

        abort_unless($user->canEditUnit((int) $data['consumer_unit_id']), 403);
        abort_unless(ConsumerUnit::findOrFail($data['consumer_unit_id'])->isGenerator(), 422, 'A UC informada não é geradora.');

        $month = substr($data['reference_month'], 0, 7).'-01';

        $generation = SolarGeneration::updateOrCreate(
            ['consumer_unit_id' => $data['consumer_unit_id'], 'reference_month' => $month],
            ['generated_kwh' => $data['generated_kwh'], 'source' => 'manual'],
        );

        return response()->json(['data' => $this->present($generation->load('consumerUnit'))], 201);
    }

    public function destroy(Request $request, SolarGeneration $generation): JsonResponse
    {
        abort_unless($request->user()->can('delete', $generation), 403);

        $generation->delete();

        return response()->json(null, 204);
    }

    /**
     * Importa a planilha "Estatísticas Diárias" (.xlsx) de uma UC geradora, soma os dias pelo período
     * de leitura das faturas e devolve os meses que ainda dependem de decisão do usuário.
     */
    public function import(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->can('geracao.editar'), 403);

        $data = $request->validate([
            'consumer_unit_id' => ['required', 'integer', 'exists:consumer_units,id'],
            'file' => ['required', 'file', 'max:5120', 'extensions:xlsx'],
        ]);

        $unit = ConsumerUnit::findOrFail($data['consumer_unit_id']);
        abort_unless($user->canEditUnit($unit->id), 403);
        abort_unless($unit->isGenerator(), 422, 'A UC informada não é geradora.');

        try {
            $rows = app(DailyProductionSpreadsheet::class)->read($request->file('file')->getRealPath());
        } catch (Throwable $e) {
            return response()->json(['message' => 'Não foi possível ler a planilha: '.$e->getMessage()], 422);
        }

        foreach ($rows as $row) {
            SolarDailyProduction::updateOrCreate(
                ['consumer_unit_id' => $unit->id, 'date' => $row['date']],
                ['generated_kwh' => $row['kwh'], 'revenue' => $row['revenue']],
            );
        }

        $result = app(SolarGenerationConsolidator::class)->consolidate($unit);

        return response()->json(['data' => [
            'days' => count($rows),
            'updated' => $result['updated'],
            'pending' => $this->presentPending($result['pending'], $unit),
        ]], 201);
    }

    /** Meses da planilha que não foram gravados sozinhos (incompletos ou divergentes do lançamento manual). */
    public function pending(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('geracao.editar'), 403);

        $consolidator = app(SolarGenerationConsolidator::class);
        $items = UnitOptions::editableGenerators()->get()
            ->flatMap(fn (ConsumerUnit $unit) => $this->presentPending($consolidator->pending($unit), $unit))
            ->values();

        return response()->json(['data' => $items]);
    }

    /** Aplica a decisão do usuário: gravar o valor da planilha ou descartar a pendência. */
    public function resolve(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('geracao.editar'), 403);

        $data = $request->validate([
            'save' => ['nullable', 'array'],
            'save.*' => ['date_format:Y-m'],
            'dismiss' => ['nullable', 'array'],
            'dismiss.*' => ['date_format:Y-m'],
        ]);

        $consolidator = app(SolarGenerationConsolidator::class);
        $saved = $dismissed = 0;
        foreach (UnitOptions::editableGenerators()->get() as $unit) {
            $saved += $consolidator->apply($unit, $data['save'] ?? []);
            $dismissed += $consolidator->dismiss($unit, $data['dismiss'] ?? []);
        }

        return response()->json(['data' => ['saved' => $saved, 'dismissed' => $dismissed]]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $periods
     * @return list<array<string, mixed>>
     */
    private function presentPending($periods, ConsumerUnit $unit): array
    {
        return $periods->map(fn (array $p) => [
            'unit_id' => $unit->id,
            'unit_name' => $unit->name,
            'month' => substr($p['month'], 0, 7),
            'start' => $p['start']->format('Y-m-d'),
            'end' => $p['end']->format('Y-m-d'),
            'days' => $p['days'],
            'expected_days' => $p['expected'],
            'kwh' => $p['kwh'],
            'complete' => $p['complete'],
            'existing_kwh' => $p['existing']?->generated_kwh,
            'existing_source' => $p['existing']?->source,
        ])->values()->all();
    }

    /** @return array<string, mixed> */
    private function present(SolarGeneration $g): array
    {
        return [
            'id' => $g->id,
            'consumer_unit_id' => $g->consumer_unit_id,
            'unit_name' => $g->consumerUnit?->name,
            'reference_month' => $g->reference_month->format('Y-m-d'),
            'generated_kwh' => $g->generated_kwh,
            'source' => $g->source === 'manual' ? 'manual' : 'planilha',
        ];
    }
}
