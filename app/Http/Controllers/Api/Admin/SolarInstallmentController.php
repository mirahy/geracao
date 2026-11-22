<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\ConsumerUnit;
use App\Models\SolarInstallment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Parcelas mensais das placas solares (por UC geradora). */
class SolarInstallmentController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', SolarInstallment::class), 403);

        $rows = SolarInstallment::with('consumerUnit')->orderByDesc('start_month')->get()
            ->map(fn (SolarInstallment $i) => $this->present($i))->values();

        return response()->json(['data' => $rows]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', SolarInstallment::class), 403);

        $data = $this->validated($request);
        abort_unless($request->user()->canEditUnit((int) $data['consumer_unit_id']), 403);

        return response()->json(['data' => $this->present(SolarInstallment::create($data)->load('consumerUnit'))], 201);
    }

    public function update(Request $request, SolarInstallment $installment): JsonResponse
    {
        abort_unless($request->user()->can('update', $installment), 403);

        $data = $this->validated($request);
        abort_unless($request->user()->canEditUnit((int) $data['consumer_unit_id']), 403);
        $installment->update($data);

        return response()->json(['data' => $this->present($installment->fresh('consumerUnit'))]);
    }

    public function destroy(Request $request, SolarInstallment $installment): JsonResponse
    {
        abort_unless($request->user()->can('delete', $installment), 403);

        $installment->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'consumer_unit_id' => ['required', 'integer', 'exists:consumer_units,id'],
            'amount' => ['required', 'numeric', 'min:0'],
            'start_month' => ['required', 'date_format:Y-m'],
            'end_month' => ['nullable', 'date_format:Y-m', 'after_or_equal:start_month'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        if (! ConsumerUnit::findOrFail($data['consumer_unit_id'])->isGenerator()) {
            throw ValidationException::withMessages(['consumer_unit_id' => ['A UC informada não é geradora.']]);
        }

        $data['start_month'] .= '-01';
        $data['end_month'] = empty($data['end_month']) ? null : $data['end_month'].'-01';

        return $data;
    }

    /** @return array<string, mixed> */
    private function present(SolarInstallment $i): array
    {
        return [
            'id' => $i->id,
            'consumer_unit_id' => $i->consumer_unit_id,
            'unit_name' => $i->consumerUnit?->name,
            'amount' => $i->amount,
            'start_month' => $i->start_month->format('Y-m-d'),
            'end_month' => $i->end_month?->format('Y-m-d'),
            'description' => $i->description,
        ];
    }
}
