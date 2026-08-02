<?php

namespace App\Filament\Pages\Settings;

use App\Models\SyncRun;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class NewUrlsOverTimeChart extends ChartWidget
{
    protected static ?string $heading = 'New URLs added per day';

    protected static ?string $maxHeight = '260px';

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = now()->subDays(29)->startOfDay();

        $rows = SyncRun::query()
            ->where('started_at', '>=', $start)
            ->select(
                DB::raw('date(started_at) as bucket'),
                DB::raw('sum(added) as added'),
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get()
            ->keyBy(fn ($row) => Carbon::parse($row->bucket)->toDateString());

        $labels = [];
        $data = [];
        for ($day = $start->copy(); $day->lte(now()); $day->addDay()) {
            $key = $day->toDateString();
            $labels[] = $day->format('M j');
            $data[] = (int) ($rows->get($key)->added ?? 0);
        }

        return [
            'datasets' => [
                [
                    'label' => 'New URLs added',
                    'data' => $data,
                    'backgroundColor' => '#14b8c6',
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
        ];
    }
}
