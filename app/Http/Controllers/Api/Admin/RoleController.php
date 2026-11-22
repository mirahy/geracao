<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\ActivityLog\ActivityLogger;
use App\Support\AccessControl;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;

/** Perfis e permissões. */
class RoleController extends Controller
{
    /** Catálogo de permissões agrupadas (grupo => [{name, label}]). */
    public function permissions(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Role::class), 403);

        $groups = collect(AccessControl::groups())->map(fn (array $items, string $group) => [
            'group' => $group,
            'permissions' => collect($items)->map(fn (string $label, string $name) => ['name' => $name, 'label' => $label])->values(),
        ])->values();

        return response()->json(['data' => $groups]);
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('viewAny', Role::class), 403);

        $roles = Role::with('permissions')->orderBy('name')->get()
            ->map(fn (Role $r) => $this->present($r))->values();

        return response()->json(['data' => $roles]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('create', Role::class), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AccessControl::all()))],
        ]);

        $role = Role::create(['name' => $data['name'], 'guard_name' => 'web']);
        AccessControl::syncRolePermissions($role, $data['permissions'] ?? []);
        ActivityLogger::membershipChanged($role, 'Permissões', [], $data['permissions'] ?? []);

        return response()->json(['data' => $this->present($role->load('permissions'))], 201);
    }

    public function update(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('update', $role), 403);

        $isAdmin = $role->name === AccessControl::ADMIN_ROLE;
        $data = $request->validate([
            'name' => [$isAdmin ? 'nullable' : 'required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
            'permissions' => ['nullable', 'array'],
            'permissions.*' => ['string', Rule::in(array_keys(AccessControl::all()))],
        ]);

        if (! $isAdmin && isset($data['name'])) {
            $role->update(['name' => $data['name']]);
        }
        if (array_key_exists('permissions', $data)) {
            $before = $role->permissions()->pluck('name')->all();
            AccessControl::syncRolePermissions($role, $isAdmin ? array_keys(AccessControl::all()) : ($data['permissions'] ?? []));
            ActivityLogger::membershipChanged($role, 'Permissões', $before, $role->permissions()->pluck('name')->all());
        }

        return response()->json(['data' => $this->present($role->fresh()->load('permissions'))]);
    }

    public function destroy(Request $request, Role $role): JsonResponse
    {
        abort_unless($request->user()->can('delete', $role), 403);

        $role->delete();

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function present(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->permissions->pluck('name')->sort()->values(),
            'users_count' => AccessControl::roleUsersCount($role),
            'protected' => $role->name === AccessControl::ADMIN_ROLE,
        ];
    }
}
