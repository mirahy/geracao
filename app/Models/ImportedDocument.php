<?php

namespace App\Models;

use App\Models\Concerns\ScopedToAccessibleUnits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ImportedDocument extends Model
{
    use ScopedToAccessibleUnits;

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

    protected static function booted(): void
    {
        static::deleting(function (ImportedDocument $document): void {
            if ($document->path) {
                Storage::disk('local')->delete($document->path);
            }
        });
    }

    public function hasFile(): bool
    {
        return $this->path && Storage::disk('local')->exists($this->path);
    }

    public function consumerUnit(): BelongsTo
    {
        return $this->belongsTo(ConsumerUnit::class);
    }
}
