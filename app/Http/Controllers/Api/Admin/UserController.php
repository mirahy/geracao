<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\UnitAccess;
use App\Models\UnitRequest;
use App\Models\User;
use App\Services\UnitRequests\UnitRequestService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Gestão de usuários (perfis e unidades de cada um). */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', User::class), 403);

        $users = User::with(['roles', 'unitAccesses'])->orderBy('name')->get()
            ->map(fn (User $u) => $this->present($u))->values();

        return response()->json(['data' => $users]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('view', $user), 403);

        return response()->json(['data' => $this->present($user)]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', User::class), 403);

        $data = $request->validate($this->rules());

        $user = DB::transaction(function () use ($data) {
            $user = User::create([
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => $data['status'] ?? User::ACTIVE,
            ]);
            $this->syncRelations($user, $data);

            return $user;
        });

        return response()->json(['data' => $this->present($user->fresh(['roles', 'unitAccesses']))], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('update', $user), 403);

        $data = $request->validate($this->rules($user));

        DB::transaction(function () use ($user, $data) {
            $user->fill(collect($data)->only(['name', 'email', 'status'])->all());
            if (! empty($data['password'])) {
                $user->password = $data['password'];
            }
            $user->save();
            $this->syncRelations($user, $data);
        });

        return response()->json(['data' => $this->present($user->fresh(['roles', 'unitAccesses']))]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('delete', $user), 403);

        $user->tokens()->delete();
        $user->delete();

        return response()->json(null, 204);
    }

    /** Libera a unidade que o cadastro pendente solicitou (somente leitura). */
    public function grantRequestedUnit(Request $request, User $user): JsonResponse
    {
        abort_unless($request->user()->can('update', $user), 403);

        $pending = $user->unitRequests()->where('status', UnitRequest::PENDING)->latest()->first();
        if (! $pending) {
            throw ValidationException::withMessages(['user' => ['O usuário não tem solicitação pendente.']]);
        }

        try {
            app(UnitRequestService::class)->approve($pending, $request->user());
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['user' => [$e->getMessage()]]);
        }

        return response()->json(['data' => $this->present($user->fresh(['roles', 'unitAccesses']))]);
    }

    /** @return array<string, mixed> */
    private function rules(?User $user = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user?->id)],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'status' => ['nullable', Rule::in([User::ACTIVE, User::PENDING])],
            'roles' => ['nullable', 'array'],
            'roles.*' => ['string', Rule::exists('roles', 'name')],
            'unit_accesses' => ['nullable', 'array'],
            'unit_accesses.*.consumer_unit_id' => ['required', 'integer', 'exists:consumer_units,id', 'distinct'],
            'unit_accesses.*.can_edit' => ['boolean'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    private function syncRelations(User $user, array $data): void
    {
        if (array_key_exists('roles', $data)) {
            $user->syncRoles($data['roles'] ?? []);
        }

        if (array_key_exists('unit_accesses', $data)) {
            $wanted = collect($data['unit_accesses'] ?? [])->keyBy('consumer_unit_id');
            $user->unitAccesses()->whereNotIn('consumer_unit_id', $wanted->keys())->delete();
            foreach ($wanted as $unitId => $row) {
                UnitAccess::updateOrCreate(
                    ['user_id' => $user->id, 'consumer_unit_id' => $unitId],
                    ['can_edit' => (bool) ($row['can_edit'] ?? false)],
                );
            }
        }
    }

    /** @return array<string, mixed> */
    private function present(User $user): array
    {
        $requested = $user->unitRequests()->where('status', UnitRequest::PENDING)->latest()->first();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'status' => $user->status,
            'roles' => $user->roles->pluck('name')->values(),
            'unit_accesses' => $user->unitAccesses->map(fn (UnitAccess $a) => [
                'consumer_unit_id' => $a->consumer_unit_id,
                'can_edit' => $a->can_edit,
            ])->values(),
            'requested_unit' => $requested?->consumerUnit?->name,
        ];
    }
}
