<?php

namespace App\Console\Commands;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Services\CpanelIpBlockerService;
use App\Services\GatewaySettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AutoBlockAbusiveIpsCommand extends Command
{
    protected $signature = 'gateway:auto-block-ips';

    protected $description = 'Block IPs that exceeded the daily request threshold, escalating the block duration on repeat offenses';

    public function handle(GatewaySettingsService $settings, CpanelIpBlockerService $cpanel): int
    {
        // Lift auto-blocks whose window has passed. Manual blocks (blocked_until = null)
        // are untouched here - only an admin can lift those. Fetched as models (not a single
        // bulk update) because each one that was synced to cPanel also needs removing there -
        // otherwise it would stay blocked at the web-server level forever, even after our own
        // record says it's expired.
        $expiredRecords = GatewayBlockedIp::query()
            ->where('is_active', true)
            ->whereNotNull('blocked_until')
            ->where('blocked_until', '<=', now())
            ->get();

        foreach ($expiredRecords as $record) {
            $cpanel->unblock($record);
            $record->update(['is_active' => false]);
        }

        if ($expiredRecords->isNotEmpty()) {
            $this->info("Lifted {$expiredRecords->count()} expired auto-block(s).");
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
