<?php

namespace App\Filament\Pages\Analytics;

use App\Models\AnalyticsPurchase;
use App\Support\AnalyticsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class RevenueOverTimeChart extends ChartWidget
{
    protected static ?string $heading = 'Purchases and net revenue';

    protected static ?string $maxHeight = '290px';

    public string $period = '30days';

    public string $language = 'all';

    public string $device = 'all';

    public string $country = 'all';

    public string $userState = 'all';

    public string $currency = 'all';

    #[On('analytics-filters-updated')]
    public function onFiltersUpdated(string $period, string $language, string $device, string $country, string $userState, string $currency = 'all'): void
    {
        $this->period = $period;
        $this->language = $language;
        $this->device = $device;
        $this->country = $country;
        $this->userState = $userState;
        $this->currency = $currency;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = AnalyticsPeriod::start($this->period)
            ?? AnalyticsPurchase::query()->min('paid_at')
            ?? now()->startOfDay();
        $isHourly = $this->period === 'today';
        $driver = DB::connection()->getDriverName();
        $bucket = $isHourly
            ? ($driver === 'sqlite' ? "strftime('%H:00', paid_at)" : "date_format(paid_at, '%H:00')")
            : 'date(paid_at)';

        $rows = AnalyticsPurchase::query()
            ->where('site_id', 'smm-plus')
            ->whereIn('status', AnalyticsPurchase::REVENUE_STATUSES)
            ->where('paid_at', '>=', $start)
            ->when($this->language !== 'all', fn (Builder $query) => $query->where('language', $this->language))
            ->when($this->device !== 'all', fn (Builder $query) => $query->where('device_type', $this->device))
            ->when($this->userState !== 'all', fn (Builder $query) => $query->where('user_state', $this->userState))
            ->when($this->country !== 'all', fn (Builder $query) => $query->where('country_code', $this->country))
            ->when($this->currency !== 'all', fn (Builder $query) => $query->where('currency', $this->currency))
            ->select(
                DB::raw("{$bucket} as bucket"),
                'currency',
                DB::raw('count(*) as purchases'),
                DB::raw('sum(gross_amount - refunded_amount) as net_revenue'),
            )
            ->groupBy('bucket', 'currency')
            ->orderBy('bucket')
            ->get();

        $labels = $rows->pluck('bucket')->unique()->values();
        $orders = $rows->groupBy('bucket')->map(fn ($items) => $items->sum('purchases'));
        $palette = ['#7c3aed', '#0891b2', '#ea580c', '#16a34a', '#dc2626'];
        $datasets = [[
            'label' => 'Purchases',
            'data' => $labels->map(fn ($label) => (int) ($orders[$label] ?? 0)),
            'backgroundColor' => 'rgba(37, 99, 235, 0.45)',
            'borderColor' => '#2563eb',
            'yAxisID' => 'orders',
        ]];

        foreach ($rows->pluck('currency')->unique()->values() as $index => $currency) {
            $values = $rows->where('currency', $currency)->keyBy('bucket');
            $datasets[] = [
                'type' => 'line',
                'label' => "Net revenue ({$currency})",
                'data' => $labels->map(fn ($label) => round((float) ($values->get($label)?->net_revenue ?? 0), 2)),
                'borderColor' => $palette[$index % count($palette)],
                'backgroundColor' => $palette[$index % count($palette)],
                'tension' => 0.3,
                'yAxisID' => 'revenue',
            ];
        }

        return ['datasets' => $datasets, 'labels' => $labels];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'orders' => ['beginAtZero' => true, 'position' => 'left', 'ticks' => ['precision' => 0]],
                'revenue' => ['beginAtZero' => true, 'position' => 'right', 'grid' => ['drawOnChartArea' => false]],
            ],
        ];
    }
}
