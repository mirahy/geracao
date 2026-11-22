<?php

namespace Tests\Feature;

use App\Filament\Pages\UnitAccessRequests;
use App\Filament\Resources\UnitRequests\Pages\ManageUnitRequests;
use App\Models\ConsumerUnit;
use App\Models\UnitAccess;
use App\Models\UnitRequest;
use App\Models\User;
use App\Services\UnitRequests\UnitRequestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnitRequestsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $member;

    /** @var list<ConsumerUnit> */
    private array $units;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->admin = User::factory()->administrator()->create();
        $this->member = User::factory()->create();
        $this->member->assignRole('Consulta');

        foreach (range(1, 7) as $i) {
            $this->units[] = ConsumerUnit::create(['name' => "Unidade {$i}", 'uc_number' => "10000{$i}", 'role' => 'receiver']);
        }
        UnitAccess::create(['user_id' => $this->member->id, 'consumer_unit_id' => $this->units[0]->id, 'can_edit' => false]);
    }

    private function notificationsOf(User $user): array
    {
        return $user->notifications()->get()->map(fn ($notification) => $notification->data['title'])->all();
    }

    public function test_active_user_requests_only_units_without_access_or_pending_request(): void
    {
        $this->actingAs($this->member);

        Livewire::test(UnitAccessRequests::class)
            ->callAction('newRequest', ['consumer_unit_id' => $this->units[1]->id, 'message' => 'Preciso dos dados'])
            ->assertNotified('Solicitação enviada');

        $request = UnitRequest::firstOrFail();
        $this->assertSame([$this->member->id, $this->units[1]->id, 'pending'], [$request->user_id, $request->consumer_unit_id, $request->status]);
        $this->assertContains('Solicitação de acesso a unidade', $this->notificationsOf($this->admin));

        $available = app(UnitRequestService::class)->availableUnits($this->member)->pluck('id');
        $this->assertFalse($available->contains($this->units[0]->id), 'já tem acesso');
        $this->assertFalse($available->contains($this->units[1]->id), 'já pedida');
        $this->assertTrue($available->contains($this->units[2]->id));

        Livewire::test(UnitAccessRequests::class)
            ->callAction('newRequest', ['consumer_unit_id' => $this->units[1]->id])
            ->assertHasActionErrors(['consumer_unit_id']);
        $this->assertSame(1, UnitRequest::count());
    }

    public function test_pending_requests_are_limited_to_five_per_user(): void
    {
        $this->actingAs($this->member);
        $service = app(UnitRequestService::class);

        foreach (range(1, 5) as $i) {
            $service->submit($this->member, $this->units[$i]->id);
        }
        // a 6ª unidade só existe porque criamos 7; já há 5 pendentes
        Livewire::test(UnitAccessRequests::class)
            ->callAction('newRequest', ['consumer_unit_id' => $this->units[6]->id])
            ->assertNotified('Não foi possível enviar');

        $this->assertSame(5, UnitRequest::count());

        $this->expectException(\DomainException::class);
        $service->submit($this->member, $this->units[6]->id);
    }

    public function test_user_sees_and_cancels_only_own_pending_requests(): void
    {
        $other = User::factory()->create();
        $other->assignRole('Consulta');
        $service = app(UnitRequestService::class);
        $mine = $service->submit($this->member, $this->units[1]->id);
        $theirs = $service->submit($other, $this->units[2]->id);

        $this->actingAs($this->member);
        Livewire::test(UnitAccessRequests::class)
            ->assertCanSeeTableRecords([$mine])
            ->assertCanNotSeeTableRecords([$theirs])
            ->callTableAction('cancel', $mine);

        $this->assertSame(UnitRequest::CANCELLED, $mine->fresh()->status);

        $this->expectException(\DomainException::class);
        $service->cancel($theirs, $this->member);
    }

    public function test_admin_approves_as_read_only_and_user_is_notified(): void
    {
        $request = app(UnitRequestService::class)->submit($this->member, $this->units[1]->id);

        $this->actingAs($this->admin);
        Livewire::test(ManageUnitRequests::class)
            ->assertCanSeeTableRecords([$request])
            ->callTableAction('approve', $request, ['can_edit' => false]);

        $access = UnitAccess::where(['user_id' => $this->member->id, 'consumer_unit_id' => $this->units[1]->id])->firstOrFail();
        $this->assertFalse($access->can_edit);
        $this->assertSame([UnitRequest::APPROVED, $this->admin->id], [$request->fresh()->status, $request->fresh()->decided_by]);
        $this->assertContains('Solicitação aprovada', $this->notificationsOf($this->member));
        $this->assertTrue($this->member->fresh()->canViewUnit($this->units[1]->id));
        $this->assertFalse($this->member->fresh()->canEditUnit($this->units[1]->id));
    }

    public function test_admin_can_grant_edit_and_never_downgrades_an_existing_edit_access(): void
    {
        $service = app(UnitRequestService::class);

        $edit = $service->submit($this->member, $this->units[1]->id);
        $service->approve($edit, $this->admin, true);
        $this->assertTrue(UnitAccess::where('consumer_unit_id', $this->units[1]->id)->value('can_edit'));

        UnitAccess::where('consumer_unit_id', $this->units[0]->id)->update(['can_edit' => true]);
        $existing = UnitRequest::create(['user_id' => $this->member->id, 'consumer_unit_id' => $this->units[0]->id, 'status' => 'pending']);
        $service->approve($existing, $this->admin, false);
        $this->assertTrue(UnitAccess::where('consumer_unit_id', $this->units[0]->id)->value('can_edit'));
        $this->assertSame(1, UnitAccess::where('consumer_unit_id', $this->units[0]->id)->count());
    }

    public function test_admin_rejects_with_reason_and_cannot_answer_twice(): void
    {
        $service = app(UnitRequestService::class);
        $request = $service->submit($this->member, $this->units[1]->id);

        $this->actingAs($this->admin);
        Livewire::test(ManageUnitRequests::class)->callTableAction('reject', $request, ['note' => 'Sem relação com a unidade']);

        $this->assertSame(UnitRequest::REJECTED, $request->fresh()->status);
        $this->assertSame('Sem relação com a unidade', $request->fresh()->decision_note);
        $this->assertContains('Solicitação recusada', $this->notificationsOf($this->member));
        $this->assertFalse($this->member->fresh()->canViewUnit($this->units[1]->id));

        // recusada: pode pedir de novo
        $this->assertTrue($service->availableUnits($this->member)->contains('id', $this->units[1]->id));

        $this->expectException(\DomainException::class);
        $service->approve($request->fresh(), $this->admin);
    }

    public function test_only_administrator_manages_requests(): void
    {
        $manager = User::factory()->create();
        $manager->assignRole('Gestor');

        $this->actingAs($manager)->get(route('filament.admin.resources.unit-requests.index'))->assertForbidden();
        $this->actingAs($this->member)->get(route('filament.admin.resources.unit-requests.index'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('filament.admin.resources.unit-requests.index'))->assertOk();
    }

    public function test_pending_account_approval_activates_user_with_a_single_notification(): void
    {
        $pending = User::factory()->create(['status' => User::PENDING]);
        $pending->assignRole('Consulta');
        $request = UnitRequest::create(['user_id' => $pending->id, 'consumer_unit_id' => $this->units[2]->id, 'status' => 'pending']);

        app(UnitRequestService::class)->approve($request, $this->admin);

        $this->assertFalse($pending->fresh()->isPending());
        $this->assertSame(UnitRequest::APPROVED, $request->fresh()->status);
        $this->assertSame(['Acesso liberado'], $this->notificationsOf($pending));
    }

    public function test_request_page_is_hidden_from_pending_accounts(): void
    {
        $pending = User::factory()->create(['status' => User::PENDING]);

        $this->assertFalse((function () use ($pending) {
            $this->actingAs($pending);

            return UnitAccessRequests::canAccess();
        })());

        $this->actingAs($this->member)->get(UnitAccessRequests::getUrl())->assertOk();
    }
}
