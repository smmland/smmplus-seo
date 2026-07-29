<?php

namespace App\Support;

use Illuminate\Support\Carbon;

class GatewayStatsPeriod
{
    public static function start(string $period): ?Carbon
    {
        return match ($period) {
            'today' => now()->startOfDay(),
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            default => null,
        };
    }
}
