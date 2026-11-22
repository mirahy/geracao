<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Parcela mensal paga pelas placas solares, válida de start_month até end_month (inclusive). */
class SolarInstallment extends Model
{
    use RecordsActivity;

    public const ACTIVITY_NAME = 'parcela das placas';

    use ScopedToAccessibleUnits;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'start_month' => 'date:Y-m-d',
            'end_month' => 'date:Y-m-d',
            'amount' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    /** $month no formato "Y-m". */
    public function coversMonth(string $month): bool
    {
        return $this->start_month->format('Y-m') <= $month
            && ($this->end_month === null || $this->end_month->format('Y-m') >= $month);
    }
}
