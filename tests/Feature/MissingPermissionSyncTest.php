<?php

namespace Tests\Feature;

use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Permissão nova do catálogo (ex.: "logs.ver") que ainda não foi gravada no banco: a instalação não rodou
 * o seeder depois de atualizar. Salvar o perfil não pode falhar por isso.
 */
class MissingPermissionSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /** A factory de administrador roda o seeder; a permissão só some do banco depois de criar o usuário. */
    private function removeLogsPermissionFromDatabase(): void
    {
        Permission::where('name', 'logs.ver')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->assertFalse(Permission::where('name', 'logs.ver')->exists());
    }

    public function test_panel_edit_role_saves_a_catalog_permission_missing_from_the_database(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->removeLogsPermissionFromDatabase();
        $role = Role::findByName('Gestor');

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm(['grouped' => ['administracao' => ['logs.ver']]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($role->fresh()->permissions->contains('name', 'logs.ver'));
    }

    public function test_panel_create_role_saves_a_catalog_permission_missing_from_the_database(): void
    {
        $this->actingAs(User::factory()->administrator()->create());
        $this->removeLogsPermissionFromDatabase();

        Livewire::test(CreateRole::class)
            ->fillForm(['name' => 'Auditoria', 'grouped' => ['administracao' => ['logs.ver']]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(Role::findByName('Auditoria')->permissions->contains('name', 'logs.ver'));
    }

    public function test_api_role_endpoints_save_a_catalog_permission_missing_from_the_database(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');
        Sanctum::actingAs($admin);
        $this->removeLogsPermissionFromDatabase();

        $id = $this->postJson('/api/v1/admin/roles', ['name' => 'Auditoria', 'permissions' => ['logs.ver']])
            ->assertCreated()->assertJsonPath('data.permissions.0', 'logs.ver')->json('data.id');

        $this->removeLogsPermissionFromDatabase();

        $this->putJson("/api/v1/admin/roles/{$id}", ['name' => 'Auditoria', 'permissions' => ['painel.ver', 'logs.ver']])
            ->assertOk()->assertJsonCount(2, 'data.permissions');
    }
}
