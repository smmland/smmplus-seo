<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\GatewayStats;
use App\Models\GatewayRequestLog;
use App\Support\PanelSection;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

// A real-time (30s poll) view of the last minute of Free Service Gateway traffic, separate from
// DashboardStatsWidget's 24h summary above - built for "am I under attack right now", which a
// daily aggregate can't answer quickly. Rejected/blocked requests are the actual abuse signal
// here (a real visitor mix produces very few of these), not raw volume, which spikes naturally.
class GatewayLiveActivityWidget extends StatsOverviewWidget
{
    protected static ?string $pollingInterval = '30s';

    // Above the other dashboard cards - this is the one worth checking first during an incident.
    protected static ?int $sort = -1;

    private const BLOCKED_STATUSES = [
        GatewayRequestLog::STATUS_BLOCKED_IP,
        GatewayRequestLog::STATUS_TOR_EXIT_NODE,
        GatewayRequestLog::STATUS_RATE_FLOOD,
        GatewayRequestLog::STATUS_UNREASONABLE_INPUT,
        GatewayRequestLog::STATUS_INVALID_ORIGIN,
    ];

    // Deliberately simple: a real visitor mix produces close to zero rejected requests per
    // minute, so double digits in a single minute is already far outside normal.
    private const ATTACK_THRESHOLD = 10;

    public static function canView(): bool
    {
        return (auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::FREE_SERVICE)) ?? false)
            || (auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SECURITY)) ?? false);
    }

    protected function getStats(): array
    {
        $since = now()->subMinute();

        $total = GatewayRequestLog::query()->where('created_at', '>=', $since)->count();
        $blocked = GatewayRequestLog::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', self::BLOCKED_STATUSES)
            ->count();

        $underAttack = $blocked >= self::ATTACK_THRESHOLD;

        return [
            Stat::make('Gateway requests (last minute)', (string) $total)
                ->description($blocked > 0 ? "{$blocked} rejected/blocked" : 'None rejected')
                ->descriptionIcon($blocked > 0 ? 'heroicon-m-shield-exclamation' : 'heroicon-m-check-circle')
                ->color($underAttack ? 'danger' : ($blocked > 0 ? 'warning' : 'success'))
                ->url(GatewayStats::canAccess() ? GatewayStats::getUrl() : null),

            Stat::make('Status', $underAttack ? 'Possible attack' : 'Normal')
                ->description($underAttack ? "{$blocked}+ requests blocked in the last minute" : 'No unusual activity detected')
                ->descriptionIcon($underAttack ? 'heroicon-m-exclamation-triangle' : 'heroicon-m-check-badge')
                ->color($underAttack ? 'danger' : 'success'),
        ];
    }
}
