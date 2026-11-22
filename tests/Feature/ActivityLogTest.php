<?php

namespace Tests\Feature;

use App\Filament\Resources\ActivityLogs\Pages\ListActivityLogs;
use App\Models\ActivityLog;
use App\Models\ConsumerUnit;
use App\Models\User;
use App\Services\Documents\DocumentImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    private ConsumerUnit $generator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');

        $importer = app(DocumentImporter::class);
        foreach (['demonstrativo_2026_11.txt', 'fatura_geradora_2026_11.txt', 'fatura_recebedora_a_2026_11.txt'] as $file) {
            $importer->importText(file_get_contents(__DIR__.'/../Fixtures/'.$file));
        }

        $this->generator = ConsumerUnit::where('role', 'generator')->firstOrFail();
        ActivityLog::query()->delete();
    }

    private function actAs(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);
        ActivityLog::query()->delete();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_api_login_failure_login_and_logout_are_recorded_as_access(): void
    {
        $user = User::factory()->create(['password' => Hash::make('segredo123')]);
        $user->assignRole('Consulta');
        ActivityLog::query()->delete();

        $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'errada'])->assertStatus(422);
        $token = $this->postJson('/api/v1/login', ['email' => $user->email, 'password' => 'segredo123', 'device_name' => 'Pixel'])->assertOk()->json('token');
        $this->postJson('/api/v1/logout', [], ['Authorization' => 'Bearer '.$token])->assertNoContent();

        $this->assertSame(['login_failed', 'login', 'logout'], ActivityLog::orderBy('id')->pluck('event')->all());
        $this->assertSame('access', ActivityLog::first()->log_name);
        $login = ActivityLog::where('event', 'login')->first();
        $this->assertSame($user->id, $login->causer_id);
        $this->assertSame('api', $login->channel);
        $this->assertSame('Pixel', $login->properties['device']);
        $this->assertStringNotContainsString('segredo123', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_panel_login_events_are_recorded(): void
    {
        $user = User::factory()->create();
        ActivityLog::query()->delete();

        event(new Login('web', $user, false));
        event(new Failed('web', null, ['email' => 'x@example.com', 'password' => 'nunca-gravar']));

        $this->assertSame(['login', 'login_failed'], ActivityLog::orderBy('id')->pluck('event')->all());
        $this->assertStringNotContainsString('nunca-gravar', json_encode(ActivityLog::all()->toArray()));
    }

    public function test_api_views_are_recorded_once_with_filters_and_support_routes_are_skipped(): void
    {
        $this->actAs('Administrador');

        $this->getJson('/api/v1/dashboard?unit_id='.$this->generator->id.'&from=2026-10')->assertOk();
        $this->getJson('/api/v1/dashboard?unit_id='.$this->generator->id.'&from=2026-10')->assertOk();
        $this->getJson('/api/v1/months')->assertOk();
        $this->getJson('/api/v1/units')->assertOk();
        $this->getJson('/api/v1/me')->assertOk();

        $logs = ActivityLog::where('log_name', 'view')->get();
        $this->assertCount(1, $logs);
        $this->assertSame('Visualizou Painel', $logs[0]->description);
        $this->assertSame((string) $this->generator->id, (string) $logs[0]->properties['unit_id']);
        $this->assertSame('2026-10', $logs[0]->properties['from']);
    }

    public function test_changes_record_who_what_and_before_after_without_secrets(): void
    {
        $admin = $this->actAs('Administrador');

        $id = $this->postJson('/api/v1/units', ['name' => 'Casa Nova', 'role' => 'receiver', 'uc_number' => '12345678900'])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/units/{$id}", ['name' => 'Casa Nova 2', 'role' => 'receiver', 'uc_number' => '12345678900'])->assertOk();
        $this->deleteJson("/api/v1/units/{$id}")->assertNoContent();

        $logs = ActivityLog::where('log_name', 'change')->where('subject_type', ConsumerUnit::class)->orderBy('id')->get();
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('event')->all());
        $this->assertSame($admin->id, $logs[1]->causer_id);
        $this->assertSame($admin->name, $logs[1]->causer_name);
        $this->assertSame('Alterou unidade: Casa Nova 2', $logs[1]->description);
        $this->assertSame('Casa Nova', $logs[1]->properties['old']['name']);
        $this->assertSame('Casa Nova 2', $logs[1]->properties['attributes']['name']);
        $this->assertArrayNotHasKey('updated_at', $logs[1]->properties['attributes']);

        $this->getJson('/api/v1/logs?type=change&search=Alterou')->assertOk()->assertJsonPath('data.0.changes.name', ['Casa Nova', 'Casa Nova 2']);

        $this->postJson('/api/v1/admin/users', ['name' => 'Maria', 'email' => 'maria@example.com', 'password' => 'segredo123'])->assertCreated();
        $this->assertStringNotContainsString('segredo123', json_encode(ActivityLog::all()->toArray()));
        $this->assertStringNotContainsString('"password"', json_encode(ActivityLog::where('subject_type', User::class)->get()->toArray()));
    }

    public function test_role_and_permission_changes_are_recorded(): void
    {
        $this->actAs('Administrador');

        $roleId = $this->postJson('/api/v1/admin/roles', ['name' => 'Financeiro', 'permissions' => ['painel.ver', 'pagamentos.ver']])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/admin/roles/{$roleId}", ['name' => 'Financeiro', 'permissions' => ['painel.ver']])->assertOk();
        $userId = $this->postJson('/api/v1/admin/users', ['name' => 'Ana', 'email' => 'ana@example.com', 'password' => 'segredo123', 'roles' => ['Consulta']])->assertCreated()->json('data.id');

        $descriptions = ActivityLog::where('event', 'updated')->pluck('description')->implode(' | ');
        $this->assertStringContainsString('Criou perfil: Financeiro', ActivityLog::where('event', 'created')->pluck('description')->implode(' | '));
        $this->assertStringContainsString('Permissões do perfil Financeiro: + painel.ver, + pagamentos.ver', $descriptions);
        $this->assertStringContainsString('Permissões do perfil Financeiro: - pagamentos.ver', $descriptions);
        $this->assertStringContainsString('Perfis do usuário Ana: + Consulta', $descriptions);
        $this->assertNotNull($userId);
    }

    public function test_panel_page_views_are_recorded(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');
        ActivityLog::query()->delete();

        $this->actingAs($admin)->get('/')->assertOk();

        $log = ActivityLog::where('log_name', 'view')->first();
        $this->assertNotNull($log);
        $this->assertSame('Abriu o Painel', $log->description);
        $this->assertSame('panel', $log->channel);
    }

    public function test_logs_endpoint_requires_permission_and_filters_and_paginates(): void
    {
        $viewer = $this->actAs('Consulta');
        $this->getJson('/api/v1/logs')->assertForbidden();

        $admin = $this->actAs('Administrador');
        foreach (range(1, 35) as $i) {
            ActivityLog::create(['causer_type' => User::class, 'causer_id' => $viewer->id, 'causer_name' => $viewer->name, 'log_name' => 'view', 'event' => 'view', 'description' => "Visualizou item {$i}", 'channel' => 'api']);
        }
        ActivityLog::create(['causer_type' => User::class, 'causer_id' => $admin->id, 'causer_name' => $admin->name, 'log_name' => 'access', 'event' => 'login', 'description' => 'Entrou no app', 'channel' => 'api']);

        $first = $this->getJson('/api/v1/logs')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.last_page', 2)->assertJsonPath('meta.total', 36);
        $this->assertCount(2, $first->json('users'));
        $this->getJson('/api/v1/logs?page=2')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/v1/logs?type=access')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.description', 'Entrou no app');
        $this->getJson('/api/v1/logs?user_id='.$viewer->id.'&search=item 7')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/logs?from=2999-01-01')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/logs?type=xyz')->assertStatus(422);
        $this->assertFalse(ActivityLog::where('description', 'like', '%logs%')->exists(), 'consultar o registro não gera registro');
    }

    public function test_panel_resource_is_listed_only_for_who_can_see_logs(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole('Administrador');
        $log = ActivityLog::create(['log_name' => 'view', 'event' => 'view', 'description' => 'Visualizou Painel', 'channel' => 'api']);

        $this->actingAs($admin);
        Livewire::test(ListActivityLogs::class)->assertCanSeeTableRecords([$log]);

        $viewer = User::factory()->create();
        $viewer->assignRole('Consulta');
        $this->actingAs($viewer)->get('/activity-logs')->assertForbidden();
    }

    public function test_prune_command_removes_only_expired_entries(): void
    {
        config(['activitylog.delete_records_older_than_days' => 180]);
        $old = ActivityLog::create(['log_name' => 'view', 'event' => 'view', 'description' => 'antigo', 'channel' => 'api']);
        $old->forceFill(['created_at' => now()->subDays(200)])->save();
        $recent = ActivityLog::create(['log_name' => 'view', 'event' => 'view', 'description' => 'recente', 'channel' => 'api']);

        $this->artisan('activitylog:clean', ['--force' => true])->assertSuccessful();

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
    }

    public function test_administrator_role_receives_the_logs_permission(): void
    {
        $this->assertTrue(Role::where('name', 'Administrador')->first()->permissions->contains('name', 'logs.ver'));
        $this->assertFalse(Role::where('name', 'Gestor')->first()->permissions->contains('name', 'logs.ver'));
    }
}
