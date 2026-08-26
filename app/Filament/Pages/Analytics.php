<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Analytics\TrafficOverTimeChart;
use App\Filament\Pages\Analytics\RevenueOverTimeChart;
use App\Models\AnalyticsEvent;
use App\Models\AnalyticsPurchase;
use App\Support\AnalyticsPeriod;
use App\Support\PanelSection;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class Analytics extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar-square';

    protected static ?string $navigationGroup = 'Analytics';

    protected static ?string $navigationLabel = 'Website Statistics';

    protected static ?int $navigationSort = 1;

    protected static string $view = 'filament.pages.analytics';

    #[Url]
    public string $period = '30days';

    #[Url]
    public string $language = 'all';

    #[Url]
    public string $device = 'all';

    #[Url]
    public string $country = 'all';

    #[Url]
    public string $userState = 'all';

    #[Url]
    public string $currency = 'all';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::ANALYTICS)) ?? false;
    }

    protected function getHeaderWidgets(): array
    {
        return [TrafficOverTimeChart::class, RevenueOverTimeChart::class];
    }

    public function getHeaderWidgetsColumns(): int | string | array
    {
        return 1;
    }

    public function getWidgetData(): array
    {
        return [
            'period' => $this->period,
            'language' => $this->language,
            'device' => $this->device,
            'country' => $this->country,
            'userState' => $this->userState,
            'currency' => $this->currency,
        ];
    }

    public function updatedPeriod(): void
    {
        $this->refreshData();
    }

    public function updatedLanguage(): void
    {
        $this->refreshData();
    }

    public function updatedDevice(): void
    {
        $this->refreshData();
    }

    public function updatedCountry(): void
    {
        $this->refreshData();
    }

    public function updatedUserState(): void
    {
        $this->refreshData();
    }

    public function updatedCurrency(): void
    {
        $this->refreshData();
    }

    private function refreshData(): void
    {
        unset(
            $this->summary,
            $this->audienceBreakdown,
            $this->topPages,
            $this->topSources,
            $this->languageBreakdown,
            $this->countryBreakdown,
            $this->conversions,
            $this->webVitals,
            $this->notFoundPages,
            $this->purchaseSummary,
            $this->purchaseSources,
            $this->purchaseLandingPages,
            $this->recentPurchases,
        );

        $this->dispatch(
            'analytics-filters-updated',
            period: $this->period,
            language: $this->language,
            device: $this->device,
            country: $this->country,
            userState: $this->userState,
            currency: $this->currency,
        );
    }

    public function purchaseQuery(): Builder
    {
        $start = AnalyticsPeriod::start($this->period);

        return AnalyticsPurchase::query()
            ->where('site_id', 'smm-plus')
            ->whereIn('status', AnalyticsPurchase::REVENUE_STATUSES)
            ->when($start, fn (Builder $query) => $query->where('paid_at', '>=', $start))
            ->when($this->language !== 'all', fn (Builder $query) => $query->where('language', $this->language))
            ->when($this->device !== 'all', fn (Builder $query) => $query->where('device_type', $this->device))
            ->when($this->userState !== 'all', fn (Builder $query) => $query->where('user_state', $this->userState))
            ->when($this->country !== 'all', fn (Builder $query) => $query->where('country_code', $this->country))
            ->when($this->currency !== 'all', fn (Builder $query) => $query->where('currency', $this->currency));
    }

    public function baseQuery(bool $includeUserState = true): Builder
    {
        $start = AnalyticsPeriod::start($this->period);

        return AnalyticsEvent::query()
            ->where('site_id', 'smm-plus')
            ->when($start, fn (Builder $query) => $query->where('occurred_at', '>=', $start))
            ->when($this->language !== 'all', fn (Builder $query) => $query->where('language', $this->language))
            ->when($this->device !== 'all', fn (Builder $query) => $query->where('device_type', $this->device))
            ->when($includeUserState && $this->userState !== 'all', fn (Builder $query) => $query->where('user_state', $this->userState))
            ->when($this->country !== 'all', fn (Builder $query) => $query->where('country_code', $this->country));
    }

    #[Computed]
    public function audienceBreakdown()
    {
        return $this->baseQuery(includeUserState: false)
            ->where('event_name', 'page_view')
            ->select(
                'user_state',
                DB::raw('count(*) as views'),
                DB::raw('count(distinct visitor_id) as visitors'),
                DB::raw('count(distinct session_id) as sessions'),
            )
            ->groupBy('user_state')
            ->get()
            ->keyBy('user_state');
    }

    #[Computed]
    public function languageOptions(): array
    {
        return ['all' => 'All languages'] + AnalyticsEvent::query()
            ->whereNotNull('language')
            ->distinct()
            ->orderBy('language')
            ->pluck('language', 'language')
            ->all();
    }

    #[Computed]
    public function countryOptions(): array
    {
        return ['all' => 'All countries'] + AnalyticsEvent::query()
            ->whereNotNull('country_code')
            ->distinct()
            ->orderBy('country_code')
            ->pluck('country_code', 'country_code')
            ->all();
    }

    #[Computed]
    public function currencyOptions(): array
    {
        return ['all' => 'All currencies'] + AnalyticsPurchase::query()
            ->distinct()
            ->orderBy('currency')
            ->pluck('currency', 'currency')
            ->all();
    }

    #[Computed]
    public function purchaseSummary(): array
    {
        $rows = (clone $this->purchaseQuery())
            ->select(
                'currency',
                DB::raw('count(*) as purchases'),
                DB::raw('sum(gross_amount) as gross'),
                DB::raw('sum(refunded_amount) as refunds'),
                DB::raw('sum(gross_amount - refunded_amount) as net'),
            )
            ->groupBy('currency')
            ->get();
        $purchases = (int) $rows->sum('purchases');
        $sessions = (int) $this->summary['sessions'];

        return [
            'purchases' => $purchases,
            'gross' => $this->formatMoneyRows($rows, 'gross'),
            'refunds' => $this->formatMoneyRows($rows, 'refunds'),
            'net' => $this->formatMoneyRows($rows, 'net'),
            'average' => $this->formatMoneyRows($rows->map(function ($row) {
                $row->average = $row->purchases > 0 ? (float) $row->net / (int) $row->purchases : 0;

                return $row;
            }), 'average'),
            'conversion_rate' => $sessions > 0 ? round(($purchases / $sessions) * 100, 2) : 0,
        ];
    }

    #[Computed]
    public function purchaseSources()
    {
        return (clone $this->purchaseQuery())
            ->select(
                'source',
                'medium',
                'currency',
                DB::raw('count(*) as purchases'),
                DB::raw('sum(gross_amount - refunded_amount) as net'),
            )
            ->groupBy('source', 'medium', 'currency')
            ->orderByDesc('purchases')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function purchaseLandingPages()
    {
        return (clone $this->purchaseQuery())
            ->select(
                'landing_page',
                'currency',
                DB::raw('count(*) as purchases'),
                DB::raw('sum(gross_amount - refunded_amount) as net'),
            )
            ->groupBy('landing_page', 'currency')
            ->orderByDesc('purchases')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function recentPurchases()
    {
        return (clone $this->purchaseQuery())->orderByDesc('paid_at')->limit(20)->get();
    }

    private function formatMoneyRows($rows, string $column): string
    {
        if ($rows->isEmpty()) {
            return '—';
        }

        return $rows->map(fn ($row) => number_format((float) $row->{$column}, 2).' '.$row->currency)->implode(' · ');
    }

    #[Computed]
    public function summary(): array
    {
        $query = $this->baseQuery();
        $pageViews = (clone $query)->where('event_name', 'page_view')->count();
        $visitors = (clone $query)->where('event_name', 'page_view')->distinct('visitor_id')->count('visitor_id');

        $sessionRows = (clone $query)
            ->select(
                'session_id',
                DB::raw("sum(case when event_name = 'page_view' then 1 else 0 end) as page_views"),
                DB::raw("max(case when event_name = 'engagement' then coalesce(duration_ms, 0) else 0 end) as engagement_ms"),
                DB::raw("sum(case when event_name = 'conversion' then 1 else 0 end) as conversions"),
            )
            ->groupBy('session_id');

        $sessions = DB::query()->fromSub($sessionRows, 'analytics_sessions')
            ->selectRaw('count(*) as total')
            ->selectRaw('sum(case when engagement_ms >= 10000 or page_views >= 2 or conversions > 0 then 1 else 0 end) as engaged')
            ->first();

        $sessionCount = (int) ($sessions->total ?? 0);
        $engagedCount = (int) ($sessions->engaged ?? 0);
        $engagementRate = $sessionCount > 0 ? round(($engagedCount / $sessionCount) * 100, 1) : 0;
        $averageEngagementMs = (int) ((clone $query)
            ->where('event_name', 'engagement')
            ->whereNotNull('duration_ms')
            ->avg('duration_ms') ?? 0);

        return [
            'page_views' => $pageViews,
            'visitors' => $visitors,
            'sessions' => $sessionCount,
            'engagement_rate' => $engagementRate,
            'bounce_rate' => round(100 - $engagementRate, 1),
            'average_engagement_seconds' => round($averageEngagementMs / 1000, 1),
            'conversions' => (clone $query)->where('event_name', 'conversion')->count(),
        ];
    }

    #[Computed]
    public function topPages()
    {
        return (clone $this->baseQuery())
            ->select(
                'page_path',
                DB::raw("sum(case when event_name = 'page_view' then 1 else 0 end) as views"),
                DB::raw("count(distinct case when event_name = 'page_view' then visitor_id end) as visitors"),
                DB::raw("sum(case when event_name = 'page_view' and is_landing = 1 then 1 else 0 end) as entrances"),
                DB::raw("sum(case when event_name = 'engagement' then 1 else 0 end) as exits"),
                DB::raw("avg(case when event_name = 'engagement' then duration_ms end) as avg_engagement_ms"),
                DB::raw("avg(case when event_name = 'engagement' then scroll_depth end) as avg_scroll"),
            )
            ->groupBy('page_path')
            ->havingRaw("sum(case when event_name = 'page_view' then 1 else 0 end) > 0")
            ->orderByDesc('views')
            ->limit(25)
            ->get();
    }

    #[Computed]
    public function topSources()
    {
        return (clone $this->baseQuery())
            ->where('event_name', 'page_view')
            ->where('is_landing', true)
            ->select(
                'source',
                'medium',
                DB::raw('count(*) as sessions'),
                DB::raw('count(distinct visitor_id) as visitors'),
            )
            ->groupBy('source', 'medium')
            ->orderByDesc('sessions')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function languageBreakdown()
    {
        return (clone $this->baseQuery())
            ->where('event_name', 'page_view')
            ->select(
                'language',
                DB::raw('count(*) as views'),
                DB::raw('count(distinct visitor_id) as visitors'),
                DB::raw('count(distinct session_id) as sessions'),
            )
            ->groupBy('language')
            ->orderByDesc('views')
            ->get();
    }

    #[Computed]
    public function countryBreakdown()
    {
        return (clone $this->baseQuery())
            ->where('event_name', 'page_view')
            ->select(
                'country_code',
                DB::raw('count(*) as views'),
                DB::raw('count(distinct visitor_id) as visitors'),
                DB::raw('count(distinct session_id) as sessions'),
            )
            ->groupBy('country_code')
            ->orderByDesc('views')
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function conversions()
    {
        return (clone $this->baseQuery())
            ->where('event_name', 'conversion')
            ->select('target', DB::raw('count(*) as total'), DB::raw('count(distinct session_id) as sessions'))
            ->groupBy('target')
            ->orderByDesc('total')
            ->limit(15)
            ->get();
    }

    #[Computed]
    public function webVitals()
    {
        $thresholds = ['LCP' => 2500, 'INP' => 200, 'CLS' => 0.1, 'FCP' => 1800, 'TTFB' => 800];

        return (clone $this->baseQuery())
            ->where('event_name', 'web_vital')
            ->whereNotNull('metric_value')
            ->select('target', DB::raw('avg(metric_value) as average'), DB::raw('count(*) as samples'))
            ->groupBy('target')
            ->orderBy('target')
            ->get()
            ->map(function ($row) use ($thresholds) {
                $row->threshold = $thresholds[$row->target] ?? null;
                $row->status = $row->threshold !== null && (float) $row->average <= $row->threshold ? 'Good' : 'Needs attention';

                return $row;
            });
    }

    #[Computed]
    public function notFoundPages()
    {
        return (clone $this->baseQuery())
            ->where('event_name', 'page_view')
            ->where('page_type', '404')
            ->select('page_path', DB::raw('count(*) as views'), DB::raw('max(occurred_at) as last_seen'))
            ->groupBy('page_path')
            ->orderByDesc('views')
            ->limit(20)
            ->get();
    }
}
