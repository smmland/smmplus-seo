<?php

namespace App\Filament\Pages\GatewayStats;

use App\Models\GatewayRequestLog;
use App\Support\GatewayStatsPeriod;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\On;

class ServiceVolumeChart extends ChartWidget
{
    protected static ?string $heading = 'Top services by volume';

    protected static ?string $maxHeight = '260px';

    // Fixed categorical order (never cycled/reassigned) - safe for up to 8 adjacent bars.
    private const PALETTE = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#4a3aa7', '#e34948'];

    public string $period = 'today';

    #[On('gateway-period-updated')]
    public function onPeriodUpdated(string $period): void
    {
        $this->period = $period;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $start = GatewayStatsPeriod::start($this->period);

        $rows = GatewayRequestLog::query()
            ->when($start, fn ($q) => $q->where('created_at', '>=', $start))
            ->whereNotNull('service_slug')
            ->select('service_slug', DB::raw('count(*) as total'))
            ->groupBy('service_slug')
            ->orderByDesc('total')
            ->get();

        $top = $rows->take(count(self::PALETTE) - 1);
        $otherTotal = $rows->slice($top->count())->sum('total');

        $labels = $top->pluck('service_slug');
        $values = $top->pluck('total');

        if ($otherTotal > 0) {
            $labels->push('Other');
            $values->push($otherTotal);
        }

        return [
            'datasets' => [
                [
                    'label' => 'Requests',
                    'data' => $values,
                    'backgroundColor' => array_slice(self::PALETTE, 0, $labels->count()),
                ],
            ],
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => ['legend' => ['display' => false]],
            'scales' => ['y' => ['beginAtZero' => true]],
        ];
    }
}
