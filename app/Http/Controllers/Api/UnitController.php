<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsumerUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class UnitController extends Controller
{
    public function index(): JsonResponse
    {
        abort_unless(auth()->user()->can('unidades.ver'), 403);

        return response()->json([
            'data' => ConsumerUnit::orderBy('name')->get()->map(fn (ConsumerUnit $u) => self::present($u))->values(),
        ]);
    }

    public function show(Request $request, ConsumerUnit $unit): JsonResponse
    {
        abort_unless($request->user()->can('view', $unit), 403);

        return response()->json(['data' => self::present($unit, true)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', ConsumerUnit::class), 403);

        $unit = ConsumerUnit::create($this->validated($request));

        return response()->json(['data' => self::present($unit, true)], 201);
    }

    public function update(Request $request, ConsumerUnit $unit): JsonResponse
    {
        abort_unless($request->user()->can('update', $unit), 403);

        $unit->update($this->validated($request, $unit));

        return response()->json(['data' => self::present($unit->fresh(), true)]);
    }

    public function destroy(Request $request, ConsumerUnit $unit): JsonResponse
    {
        abort_unless($request->user()->can('delete', $unit), 403);

        $unit->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ConsumerUnit $unit = null): array
    {
        $request->merge(['uc_number' => preg_replace('/\D/', '', (string) $request->input('uc_number'))]);

        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'role' => ['required', Rule::in([ConsumerUnit::ROLE_GENERATOR, ConsumerUnit::ROLE_RECEIVER])],
            'uc_number' => ['required', 'string', 'min:3', 'max:20', Rule::unique('consumer_units', 'uc_number')->ignore($unit?->id)],
            'code' => ['nullable', 'string', 'max:50', Rule::unique('consumer_units', 'code')->ignore($unit?->id)],
            'active' => ['boolean'],
            'bills_solar_cost' => ['boolean'],
            'counts_in_generator_cost' => ['boolean'],
            'holder_name' => ['nullable', 'string', 'max:255'],
            'classification' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'neighborhood' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'meter_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);
    }

    /** @return array<string, mixed> */
    public static function present(ConsumerUnit $unit, bool $full = false): array
    {
        return [
            'id' => $unit->id,
            'name' => $unit->name,
            'uc_number' => $unit->uc_number,
            'formatted_uc' => $unit->formatted_uc,
            'role' => $unit->role,
            'city' => $unit->city,
            'active' => $unit->active,
            ...($full ? [
                'code' => $unit->code,
                'bills_solar_cost' => $unit->bills_solar_cost,
                'counts_in_generator_cost' => $unit->counts_in_generator_cost,
                'holder_name' => $unit->holder_name,
                'classification' => $unit->classification,
                'address' => $unit->address,
                'neighborhood' => $unit->neighborhood,
                'meter_number' => $unit->meter_number,
                'notes' => $unit->notes,
            ] : []),
        ];
    }
}
