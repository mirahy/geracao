<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use ScopedToAccessibleUnits;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'issue_date' => 'date:Y-m-d',
            'due_date' => 'date:Y-m-d',
            'paid_at' => 'date:Y-m-d',
            'reading_previous_date' => 'date:Y-m-d',
            'reading_current_date' => 'date:Y-m-d',
            'credit_cycle' => 'date:Y-m-d',
            'is_contingency' => 'boolean',
            'consumption_kwh' => 'float',
            'injected_kwh' => 'float',
            'compensated_kwh' => 'float',
            'total_amount' => 'float',
            'compensation_credit_amount' => 'float',
            'compensation_fee_amount' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('position');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(ImportedDocument::class, 'imported_document_id');
    }

    /** Economia líquida: crédito da energia compensada menos o ajuste (Fio B / Lei 14.300). */
    public function getSavingsAttribute(): float
    {
        return round($this->compensation_credit_amount - $this->compensation_fee_amount, 2);
    }

    /** Quanto a fatura custaria sem a compensação. */
    public function getAmountWithoutSolarAttribute(): float
    {
        return round($this->total_amount + $this->savings, 2);
    }
}
