<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnergyHistory extends Model
{
    use ScopedToAccessibleUnits;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'source_reference' => 'date:Y-m-d',
            'measured_kwh' => 'float',
            'injected_kwh' => 'float',
            'compensated_kwh' => 'float',
            'surplus_kwh' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
