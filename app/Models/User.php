<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    private ?array $unitAccessCache = null;

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
