<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Pagamento (total ou parcial) do rateio de uma unidade beneficiária em um mês, com o comprovante opcional. */
class RateioPayment extends Model
{
    use RecordsActivity;

    public const ACTIVITY_NAME = 'pagamento do rateio';

    use HasFactory;
    use ScopedToAccessibleUnits;

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_RECEIPT = 'comprovante';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleted(function (self $payment) {
            if ($payment->hasFile()) {
                Storage::disk('local')->delete($payment->file_path);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'paid_at' => 'datetime',
            'amount' => 'float',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hasFile(): bool
    {
        return $this->file_path !== null && $this->file_path !== '';
    }
}
