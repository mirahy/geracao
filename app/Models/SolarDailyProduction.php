<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolarDailyProduction extends Model
{
    use ScopedToAccessibleUnits;

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
