<?php

namespace App\Console\Commands;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Services\GatewaySettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoBlockAbusiveIpsCommand extends Command
{
    protected $signature = 'gateway:auto-block-ips';

    protected $description = 'Block IPs that exceeded the daily request threshold, escalating the block duration on repeat offenses';

    public function handle(GatewaySettingsService $settings): int
    {
        // Lift auto-blocks whose window has passed. Manual blocks (blocked_until = null)
        // are untouched here - only an admin can lift those.
        $expired = GatewayBlockedIp::query()
            ->where('is_active', true)
            ->whereNotNull('blocked_until')
            ->where('blocked_until', '<=', now())
            ->update(['is_active' => false]);

        if ($expired > 0) {
            $this->info("Lifted {$expired} expired auto-block(s).");
        }

        if (! $settings->isAutoBlockEnabled()) {
            $this->info('Auto-block is disabled in Gateway Settings, skipping.');

            return self::SUCCESS;
        }

        $threshold = $settings->getAutoBlockThreshold();
        $since = now()->subDay();

        // Rejections caused by being blocked/off-origin don't reflect real service usage and
        // would otherwise keep re-triggering the threshold for as long as the caller keeps
        // retrying against an already-blocked IP, so they're excluded from the count.
        $offenderIps = GatewayRequestLog::query()
            ->where('created_at', '>=', $since)
            ->whereNotIn('status', [GatewayRequestLog::STATUS_BLOCKED_IP, GatewayRequestLog::STATUS_INVALID_ORIGIN])
            ->select('ip', DB::raw('count(*) as total'))
            ->groupBy('ip')
            ->having('total', '>=', $threshold)
            ->pluck('ip');

        if ($offenderIps->isEmpty()) {
            $this->info('No IPs over the threshold.');

            return self::SUCCESS;
        }

        $currentlyBlocked = GatewayBlockedIp::query()
            ->whereIn('ip', $offenderIps)
            ->where('is_active', true)
            ->pluck('ip')
            ->flip();

        foreach ($offenderIps as $ip) {
            if ($currentlyBlocked->has($ip)) {
                continue;
            }

            $record = GatewayBlockedIp::blockWithEscalation($ip, "Auto-blocked: {$threshold}+ requests/day", $settings);

            $this->warn("Blocked {$ip} (offense #{$record->offense_count}): {$record->note}");
        }

        return self::SUCCESS;
    }
}
