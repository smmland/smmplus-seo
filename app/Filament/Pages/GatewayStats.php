<?php

namespace App\Filament\Pages;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;

class GatewayStats extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Statistics';

    protected static string $view = 'filament.pages.gateway-stats';

    #[Url]
    public string $period = 'today';

    public function updatedPeriod(): void
    {
        unset(
            $this->summary,
            $this->perService,
            $this->perUpstream,
            $this->topIps,
            $this->ipServiceBreakdown,
        );
    }

    public function blockIp(string $ip): void
    {
        GatewayBlockedIp::query()->updateOrCreate(
            ['ip' => $ip],
            ['is_active' => true, 'note' => 'Blocked from statistics page'],
        );

        unset($this->topIps);

        Notification::make()->title("Blocked {$ip}")->success()->send();
    }

    private function periodStart(): ?Carbon
    {
        return match ($this->period) {
            'today' => now()->startOfDay(),
            '7days' => now()->subDays(7),
            '30days' => now()->subDays(30),
            default => null,
        };
    }

    private function baseQuery()
    {
        $start = $this->periodStart();

        // Qualified with the table name because perUpstream() joins in gateway_services and
        // gateway_upstreams, which both also have a created_at column.
        return GatewayRequestLog::query()->when($start, fn ($q) => $q->where('gateway_request_logs.created_at', '>=', $start));
    }

    #[Computed]
    public function summary(): array
    {
        $rejectedStatuses = [GatewayRequestLog::STATUS_BLOCKED_IP, GatewayRequestLog::STATUS_INVALID_ORIGIN];
        $rateLimitedStatuses = [
            GatewayRequestLog::STATUS_GLOBAL_IP_LIMIT,
            GatewayRequestLog::STATUS_GLOBAL_TARGET_LIMIT,
            GatewayRequestLog::STATUS_IP_LIMIT,
            GatewayRequestLog::STATUS_TARGET_LIMIT,
            GatewayRequestLog::STATUS_BELOW_MIN_QUANTITY,
        ];

        $rows = (clone $this->baseQuery())
            ->select('status', DB::raw('count(*) as total'))
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total' => $rows->sum(),
            'success' => $rows->get(GatewayRequestLog::STATUS_SUCCESS, 0),
            'blocked' => $rows->only($rejectedStatuses)->sum(),
            'rate_limited' => $rows->only($rateLimitedStatuses)->sum(),
            'errors' => $rows->get(GatewayRequestLog::STATUS_UPSTREAM_ERROR, 0),
            'unique_ips' => (clone $this->baseQuery())->distinct('ip')->count('ip'),
            'unique_targets' => (clone $this->baseQuery())->whereNotNull('target')->distinct('target')->count('target'),
        ];
    }

    #[Computed]
    public function perService()
    {
        return (clone $this->baseQuery())
            ->whereNotNull('service_slug')
            ->select(
                'service_slug',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'success' then 1 else 0 end) as successful"),
                DB::raw('sum(quantity_allowed) as quantity_fulfilled'),
            )
            ->groupBy('service_slug')
            ->orderByDesc('total')
            ->get();
    }

    #[Computed]
    public function perUpstream()
    {
        return (clone $this->baseQuery())
            ->join('gateway_services', 'gateway_services.slug', '=', 'gateway_request_logs.service_slug')
            ->join('gateway_upstreams', 'gateway_upstreams.id', '=', 'gateway_services.gateway_upstream_id')
            ->select(
                'gateway_upstreams.name',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when gateway_request_logs.status = 'success' then 1 else 0 end) as successful"),
            )
            ->groupBy('gateway_upstreams.name')
            ->orderByDesc('total')
            ->get();
    }

    #[Computed]
    public function topIps()
    {
        $blockedIps = GatewayBlockedIp::query()->where('is_active', true)->pluck('ip')->flip();

        return (clone $this->baseQuery())
            ->select(
                'ip',
                DB::raw('count(*) as total'),
                DB::raw("sum(case when status = 'success' then 1 else 0 end) as successful"),
                DB::raw('count(distinct service_slug) as services_hit'),
                DB::raw('max(created_at) as last_seen'),
            )
            ->groupBy('ip')
            ->orderByDesc('total')
            ->limit(50)
            ->get()
            ->map(function ($row) use ($blockedIps) {
                $row->is_blocked = $blockedIps->has($row->ip);

                return $row;
            });
    }

    #[Computed]
    public function ipServiceBreakdown()
    {
        return (clone $this->baseQuery())
            ->whereNotNull('service_slug')
            ->select('ip', 'service_slug', DB::raw('count(*) as hits'))
            ->groupBy('ip', 'service_slug')
            ->orderByDesc('hits')
            ->limit(100)
            ->get();
    }
}
