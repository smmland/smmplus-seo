<?php

namespace App\Console\Commands;

use App\Models\GatewayBlockedIp;
use App\Services\CpanelIpBlockerService;
use App\Services\GatewaySettingsService;
use Illuminate\Console\Command;

/**
 * Opt-in safety net (Security Settings: "Auto-sync blocked IPs to .htaccess", off by default):
 * makes sure every currently active GatewayBlockedIp is actually present in cPanel's .htaccess,
 * adding whichever ones are missing in a single read + a single write
 * (CpanelIpBlockerService::addIpsToHtaccess) regardless of how many there are. Covers drift the
 * reactive per-IP sync at block time can't - a transient cPanel API failure, or cPanel getting
 * configured after IPs were already blocked - without needing an admin to notice and fix it
 * manually.
 */
class SyncBlockedIpsToHtaccessCommand extends Command
{
    protected $signature = 'gateway:sync-blocked-ips-to-htaccess';

    protected $description = 'Adds any actively-blocked IP missing from cPanel\'s .htaccess, if auto-sync is enabled';

    public function handle(GatewaySettingsService $settings, CpanelIpBlockerService $cpanel): int
    {
        if (! $settings->isAutoSyncBlockedIpsEnabled()) {
            return self::SUCCESS;
        }

        $ips = GatewayBlockedIp::query()
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now()))
            ->pluck('ip')
            ->all();

        if (empty($ips)) {
            return self::SUCCESS;
        }

        $result = $cpanel->addIpsToHtaccess($ips);

        if (! $result['ok']) {
            $this->warn("Failed to sync blocked IPs to cPanel's .htaccess: {$result['error']}");

            return self::SUCCESS;
        }

        if ($result['added'] > 0) {
            // Only the ones that weren't already marked synced - avoids a pointless write every
            // tick once everything is caught up.
            GatewayBlockedIp::query()
                ->whereIn('ip', $ips)
                ->whereNull('cpanel_synced_at')
                ->update(['cpanel_synced_at' => now(), 'cpanel_sync_error' => null]);

            $this->info("Synced {$result['added']} previously-missing blocked IP(s) to cPanel's .htaccess.");
        }

        return self::SUCCESS;
    }
}
