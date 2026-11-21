<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SolarGeneration extends Model
{
    use ScopedToAccessibleUnits;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'generated_kwh' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
