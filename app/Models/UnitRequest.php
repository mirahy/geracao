<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UnitRequest extends Model
{
    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    protected $guarded = [];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class)->withoutGlobalScopes();
    }
}
