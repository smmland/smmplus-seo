<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class AnalyticsPeriod
{
    public static function start(string $period): ?Carbon
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            '7days' => now()->subDays(6)->startOfDay(),
            '30days' => now()->subDays(29)->startOfDay(),
            '90days' => now()->subDays(89)->startOfDay(),
            'all' => null,
            default => now()->subDays(29)->startOfDay(),
        };
    }
}
