<?php

namespace App\Support;

use Carbon\CarbonImmutable;

final class MonthOptions
{
    /** Meses (Y-m-01 => "Novembro/2026") dos últimos $past meses até o mês seguinte. */
    public static function recent(int $past = 48): array
    {
        $options = [];
        $cursor = CarbonImmutable::now()->startOfMonth()->addMonth();
        for ($i = 0; $i <= $past; $i++) {
            $options[$cursor->format('Y-m-d')] = ucfirst($cursor->translatedFormat('F/Y'));
            $cursor = $cursor->subMonth();
        }

        return $options;
    }
}
