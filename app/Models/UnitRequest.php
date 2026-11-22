<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pedido de acesso (somente leitura) a uma unidade consumidora; o administrador aprova ou recusa. */
class UnitRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const CANCELLED = 'cancelled';

    /** Máximo de pedidos pendentes por usuário. */
    public const MAX_PENDING = 5;

    public const STATUS_LABELS = [
        self::PENDING => 'Pendente',
        self::APPROVED => 'Aprovada',
        self::REJECTED => 'Recusada',
        self::CANCELLED => 'Cancelada',
    ];

    public const STATUS_COLORS = [
        self::PENDING => 'warning',
        self::APPROVED => 'success',
        self::REJECTED => 'danger',
        self::CANCELLED => 'gray',
    ];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class)->withoutGlobalScopes();
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
