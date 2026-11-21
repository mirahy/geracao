<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransfer extends Model
{
    use ScopedToAccessibleUnits;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'cycle_month' => 'date:Y-m-d',
            'kwh' => 'float',
        ];
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(EnergyStatement::class, 'energy_statement_id');
    }

    public function source(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class, 'source_unit_id');
    }

    public function target(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class, 'target_unit_id');
    }

    public function accessibleUnitColumns(): array
    {
        return ['source_unit_id', 'target_unit_id'];
    }

    public function accessibleUnitId(): ?int
    {
        return $this->source_unit_id;
    }
}
