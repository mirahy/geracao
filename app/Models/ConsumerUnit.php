<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConsumerUnit extends Model
{
    use HasFactory;

    public const ROLE_GENERATOR = 'generator';
    public const ROLE_RECEIVER = 'receiver';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function statements(): HasMany
    {
        return $this->hasMany(EnergyStatement::class);
    }

    public function histories(): HasMany
    {
        return $this->hasMany(EnergyHistory::class);
    }

    public function generations(): HasMany
    {
        return $this->hasMany(SolarGeneration::class);
    }

    public function transfersSent(): HasMany
    {
        return $this->hasMany(CreditTransfer::class, 'source_unit_id');
    }

    public function transfersReceived(): HasMany
    {
        return $this->hasMany(CreditTransfer::class, 'target_unit_id');
    }

    public function isGenerator(): bool
    {
        return $this->role === self::ROLE_GENERATOR;
    }

    /** 91735805198 => 917.358.051-98 */
    public static function formatUcNumber(string $digits): string
    {
        $body = substr($digits, 0, -2);
        $dv = substr($digits, -2);
        $groups = [];
        while (strlen($body) > 3) {
            array_unshift($groups, substr($body, -3));
            $body = substr($body, 0, -3);
        }
        array_unshift($groups, $body);

        return implode('.', $groups).'-'.$dv;
    }

    public function getFormattedUcAttribute(): string
    {
        return self::formatUcNumber($this->uc_number);
    }
}
