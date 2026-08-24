<?php

namespace App\Filament\Pages\Analytics;

use App\Models\AnalyticsEvent;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class TrafficOverTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Traffic over time';

    protected static ?string $maxHeight = '290px';

    public string $period = '30days';

    public string $language = 'all';

    public string $device = 'all';

    public string $country = 'all';

    #[On('analytics-filters-updated')]
    public function onFiltersUpdated(string $period, string $language, string $device, string $country): void
    {
        $this->period = $period;
        $this->language = $language;
        $this->device = $device;
        $this->country = $country;
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getData(): array
    {
        $start = AnalyticsPeriod::start($this->period)
            ?? AnalyticsEvent::query()->min('occurred_at')
            ?? now()->startOfDay();
        $isHourly = $this->period === 'today';
        $driver = DB::connection()->getDriverName();
        $bucket = $isHourly
            ? ($driver === 'sqlite' ? "strftime('%H:00', occurred_at)" : "date_format(occurred_at, '%H:00')")
            : 'date(occurred_at)';

        $rows = AnalyticsEvent::query()
            ->where('site_id', 'smm-plus')
            ->where('event_name', 'page_view')
            ->where('occurred_at', '>=', $start)
            ->when($this->language !== 'all', fn (Builder $query) => $query->where('language', $this->language))
            ->when($this->device !== 'all', fn (Builder $query) => $query->where('device_type', $this->device))
            ->when($this->country !== 'all', fn (Builder $query) => $query->where('country_code', $this->country))
            ->select(
                DB::raw("{$bucket} as bucket"),
                DB::raw('count(*) as views'),
                DB::raw('count(distinct visitor_id) as visitors'),
            )
            ->groupBy('bucket')
            ->orderBy('bucket')
            ->get();

        return [
            'datasets' => [
                [
                    'label' => 'Page views',
                    'data' => $rows->pluck('views'),
                    'borderColor' => '#2563eb',
                    'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
                    'fill' => true,
                    'tension' => 0.3,
                ],
                [
                    'label' => 'Visitors',
                    'data' => $rows->pluck('visitors'),
                    'borderColor' => '#10b981',
                    'backgroundColor' => '#10b981',
                    'tension' => 0.3,
                ],
            ],
            'labels' => $rows->pluck('bucket'),
        ];
    }

    protected function getOptions(): array
    {
        return ['scales' => ['y' => ['beginAtZero' => true]]];
    }
}
