<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Conversões de números e datas no formato brasileiro. */
final class Br
{
    private const MONTHS = [
        'janeiro' => 1, 'fevereiro' => 2, 'março' => 3, 'marco' => 3, 'abril' => 4, 'maio' => 5,
        'junho' => 6, 'julho' => 7, 'agosto' => 8, 'setembro' => 9, 'outubro' => 10,
        'novembro' => 11, 'dezembro' => 12,
    ];

    /** "1.234,56" => 1234.56 ; "-305,19" => -305.19 */
    public static function number(?string $value): float
    {
        if ($value === null || trim($value) === '') {
            return 0.0;
        }

        return (float) str_replace(',', '.', str_replace('.', '', trim($value)));
    }

    /** "17/11/2026" => 2026-11-17 */
    public static function date(?string $value): ?CarbonImmutable
    {
        if ($value && preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', trim($value), $m)) {
            return CarbonImmutable::create((int) $m[3], (int) $m[2], (int) $m[1])->startOfDay();
        }

        return null;
    }

    /** "11/2026" ou "9/2026" => 2026-11-01 */
    public static function monthYear(?string $value): ?CarbonImmutable
    {
        if ($value && preg_match('#^\s*(\d{1,2})/(\d{4})\s*$#', $value, $m)) {
            return CarbonImmutable::create((int) $m[2], (int) $m[1], 1)->startOfDay();
        }

        return null;
    }

    /** "Novembro" + 2026 => 2026-11-01 */
    public static function monthName(string $name, int $year): ?CarbonImmutable
    {
        $month = self::MONTHS[mb_strtolower(trim($name))] ?? null;

        return $month ? CarbonImmutable::create($year, $month, 1)->startOfDay() : null;
    }
}
