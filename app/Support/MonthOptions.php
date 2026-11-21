<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class MonthOptions
{
    /** Meses (Y-m-01 => "Novembro/2026") dos últimos $past meses até $future mês(es) à frente. */
    public static function recent(int $past = 48, int $future = 1): array
    {
        $options = [];
        $cursor = CarbonImmutable::now()->startOfMonth()->addMonths($future);
        for ($i = 0; $i <= $past + $future - 1; $i++) {
            $options[$cursor->format('Y-m-d')] = ucfirst($cursor->translatedFormat('F/Y'));
            $cursor = $cursor->subMonth();
        }

        return $options;
    }
}
