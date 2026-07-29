<?php

namespace App\Filament\Pages\GatewayStats;

use App\Models\GatewayRequestLog;
use App\Support\GatewayStatsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class RequestsOverTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Requests over time';

    protected static ?string $maxHeight = '260px';

    public string $period = 'today';

    #[On('gateway-period-updated')]
    public function onPeriodUpdated(string $period): void
    {
        $this->period = $period;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $isHourly = $this->period === 'today';
        $start = GatewayStatsPeriod::start($this->period)
            ?? GatewayRequestLog::query()->min('created_at')
            ?? now()->startOfDay();

        $bucketExpr = $isHourly ? "date_format(created_at, '%H:00')" : 'date(created_at)';

        $rows = GatewayRequestLog::query()
            ->where('created_at', '>=', $start)
            ->select(
                DB::raw("{$bucketExpr} as bucket"),
                DB::raw("sum(case when status = 'success' then 1 else 0 end) as successful"),
                DB::raw("sum(case when status != 'success' then 1 else 0 end) as rejected"),
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Successful',
                    'data' => $rows->pluck('successful'),
                    'borderColor' => '#2a78d6',
                    'backgroundColor' => '#2a78d6',
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Rejected',
                    'data' => $rows->pluck('rejected'),
                    'borderColor' => '#eb6834',
                    'backgroundColor' => '#eb6834',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $rows->pluck('bucket'),
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true],
            ],
        ];
    }
}
