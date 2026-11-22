<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Notifications\PanelNotification;
use App\Services\Notifications\Notifier;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitAccess extends Model
{
    use RecordsActivity;

    public const ACTIVITY_NAME = 'acesso a unidade';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['can_edit' => 'boolean'];
    }

    /** Atribuir uma unidade a um cadastro pendente libera o acesso automaticamente. */
    protected static function booted(): void
    {
        static::created(function (UnitAccess $access): void {
            $user = $access->user;
            if (! $user?->isPending()) {
                return;
            }

            $user->forceFill(['status' => User::ACTIVE])->save();
            $user->unitRequests()->where('status', UnitRequest::PENDING)->where('consumer_unit_id', $access->consumer_unit_id)
                ->update(['status' => UnitRequest::APPROVED, 'decided_at' => now()]);

            Notifier::send([$user], new PanelNotification(
                title: 'Acesso liberado',
                body: 'Sua conta foi liberada pelo administrador. Você já pode usar o sistema.',
                url: Filament::getUrl(),
                actionLabel: 'Abrir o painel',
                icon: 'heroicon-o-check-circle',
                color: 'success',
            ));
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class)->withoutGlobalScopes();
    }
}
