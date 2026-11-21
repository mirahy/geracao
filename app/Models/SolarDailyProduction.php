<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolarDailyProduction extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d', 'generated_kwh' => 'float', 'revenue' => 'float'];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
