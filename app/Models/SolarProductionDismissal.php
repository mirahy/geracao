<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolarProductionDismissal extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['reference_month' => 'date:Y-m-d', 'generated_kwh' => 'float'];
    }
}
