<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EnergyStatement extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'previous_balance_composition' => 'array',
            'balance_previous_kwh' => 'float',
            'injected_kwh' => 'float',
            'received_kwh' => 'float',
            'compensated_kwh' => 'float',
            'transferred_kwh' => 'float',
            'expired_kwh' => 'float',
            'balance_available_kwh' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ImportedDocument::class, 'imported_document_id');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(CreditTransfer::class);
    }
}
