<?php

namespace App\Models;

use App\Filament\Resources\Users\UserResource;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\RecordsActivity;
use App\Notifications\PanelNotification;
use App\Services\Notifications\Notifier;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    use RecordsActivity;

    public const ACTIVITY_NAME = 'usuário';

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    use HasRoles {
        hasPermissionTo as traitHasPermissionTo;
        checkPermissionTo as traitCheckPermissionTo;
    }

    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    private ?array $unitAccessCache = null;

    protected static function booted(): void
    {
        static::created(function (User $user): void {
            Notifier::send(
                Notifier::admins()->reject(fn (User $admin) => $admin->is($user) || $admin->id === Auth::id()),
                new PanelNotification(
                    title: 'Novo usuário criado',
                    body: "{$user->name} ({$user->email}) foi cadastrado".($user->isPending() ? ' e aguarda atribuição de unidade.' : '.'),
                    url: UserResource::getUrl('edit', ['record' => $user]),
                    actionLabel: 'Abrir usuário',
                    icon: 'heroicon-o-user-plus',
                    color: 'success',
                ),
            );
        });
    }

    /** Cadastro pendente não tem permissões (nem as do perfil). */
    public function hasPermissionTo($permission, $guardName = null): bool
    {
        return ! $this->isPending() && $this->traitHasPermissionTo($permission, $guardName);
    }

    public function checkPermissionTo($permission, $guardName = null): bool
    {
        return ! $this->isPending() && $this->traitCheckPermissionTo($permission, $guardName);
    }

    /** Cadastro novo, ainda sem unidade atribuída: não acessa nada além da tela de solicitação. */
    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function unitRequests(): HasMany
    {
        return $this->hasMany(UnitRequest::class);
    }

    /** Aplicação de uso pessoal: todo usuário cadastrado acessa o painel (não há cadastro público). */
    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    public function unitAccesses(): HasMany
    {
        return $this->hasMany(UnitAccess::class);
    }

    /** IDs das unidades que o usuário vê; null = todas. @return list<int>|null */
    public function viewableUnitIds(): ?array
    {
        if ($this->can('todas_unidades.ver') || $this->can('todas_unidades.editar')) {
            return null;
        }

        return array_keys($this->unitAccessMap());
    }

    /** IDs das unidades que o usuário edita; null = todas. @return list<int>|null */
    public function editableUnitIds(): ?array
    {
        if ($this->can('todas_unidades.editar')) {
            return null;
        }

        return array_keys(array_filter($this->unitAccessMap()));
    }

    /** O usuário alcança alguma unidade geradora? Quem só tem beneficiárias não usa as telas de geração, parcelas e importações. */
    public function hasGeneratorAccess(): bool
    {
        $ids = $this->viewableUnitIds();

        return $ids === null || ConsumerUnit::whereIn('id', $ids)->where('role', ConsumerUnit::ROLE_GENERATOR)->exists();
    }

    public function canViewUnit(?int $unitId): bool
    {
        $ids = $this->viewableUnitIds();

        return $ids === null || ($unitId !== null && in_array($unitId, $ids, true));
    }

    public function canEditUnit(?int $unitId): bool
    {
        $ids = $this->editableUnitIds();

        return $ids === null || ($unitId !== null && in_array($unitId, $ids, true));
    }

    /** @return array<int, bool> unidade => pode editar */
    private function unitAccessMap(): array
    {
        return $this->unitAccessCache ??= $this->unitAccesses()->pluck('can_edit', 'consumer_unit_id')->map(fn ($v) => (bool) $v)->all();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
