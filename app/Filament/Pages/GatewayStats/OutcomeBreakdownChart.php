<?php

namespace App\Filament\Pages\GatewayStats;

use App\Models\GatewayRequestLog;
use App\Support\GatewayStatsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class OutcomeBreakdownChart extends ChartWidget
{
    protected static ?string $heading = 'Outcome breakdown';

    protected static ?string $maxHeight = '260px';

    public string $period = 'today';

    #[On('gateway-period-updated')]
    public function onPeriodUpdated(string $period): void
    {
        $this->period = $period;
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getData(): array
    {
        $start = GatewayStatsPeriod::start($this->period);

        $rows = GatewayRequestLog::query()
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        $blocked = $rows->only([
            GatewayRequestLog::STATUS_BLOCKED_IP,
            GatewayRequestLog::STATUS_INVALID_ORIGIN,
        ])->sum();

        $rateLimited = $rows->only([
            GatewayRequestLog::STATUS_GLOBAL_IP_LIMIT,
            GatewayRequestLog::STATUS_GLOBAL_TARGET_LIMIT,
            GatewayRequestLog::STATUS_IP_LIMIT,
            GatewayRequestLog::STATUS_TARGET_LIMIT,
            GatewayRequestLog::STATUS_BELOW_MIN_QUANTITY,
        ])->sum();

        return [
            'datasets' => [
                [
                    'data' => [
                        $rows->get(GatewayRequestLog::STATUS_SUCCESS, 0),
                        $rateLimited,
                        $blocked,
                        $rows->get(GatewayRequestLog::STATUS_UPSTREAM_ERROR, 0),
                    ],
                    // Status palette (fixed, never themed): good / warning / critical / serious.
                    'backgroundColor' => ['#0ca30c', '#fab219', '#d03b3b', '#ec835a'],
                ],
            ],
            'labels' => ['Successful', 'Rate limited', 'Blocked', 'Upstream errors'],
        ];
    }
}
