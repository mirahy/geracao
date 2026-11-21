<?php

namespace App\Filament\Widgets;

final class ChartPalette
{
    private const COLORS = ['#0ea5e9', '#f97316', '#8b5cf6', '#10b981', '#ec4899', '#eab308', '#14b8a6', '#ef4444'];

    public static function color(int $index): string
    {
        return self::COLORS[$index % count(self::COLORS)];
    }
}
