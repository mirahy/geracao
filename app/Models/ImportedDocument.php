<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImportedDocument extends Model
{
    public const TYPE_INVOICE = 'invoice';
    public const TYPE_STATEMENT = 'statement';

    protected $guarded = [];

    /** Marcado pelo importador quando o arquivo já existia. */
    public bool $wasDuplicate = false;

    protected function casts(): array
    {
        return [
            'reference_month' => 'date:Y-m-d',
            'parsed' => 'array',
        ];
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
