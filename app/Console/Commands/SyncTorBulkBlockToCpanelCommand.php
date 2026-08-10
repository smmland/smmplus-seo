<?php

namespace App\Console\Commands;

use App\Models\GatewayBlockedIp;
use App\Services\CpanelIpBlockerService;
use Illuminate\Console\Command;

/**
 * Drains the queue SecuritySettings::blockAllTorExitNodes() creates when an admin clicks "Block
 * all Tor exit nodes now" - registering each queued IP with cPanel five at a time (Http::pool)
 * instead of one at a time, same no-persistent-worker reasoning as translation:process-queue.
 * Rides the once-a-minute schedule:run tick, draining whatever's queued up to its own time
 * budget rather than waiting for the next tick - a full exit-node list is thousands of IPs, so
 * this can take several ticks to fully drain. Only touches records tagged by that bulk-block
 * action (the "Tor exit-node bulk block" note prefix) - the reactive single-IP Tor block
 * (HandleGatewayCors) already syncs itself synchronously and is left alone here.
 */
class SyncTorBulkBlockToCpanelCommand extends Command
{
    protected $signature = 'gateway:sync-tor-bulk-block-to-cpanel';

    protected $description = 'Registers queued Tor exit-node bulk blocks with cPanel, five at a time';

    private const BATCH_SIZE = 5;

    private const TIME_BUDGET_SECONDS = 45;

    public function handle(CpanelIpBlockerService $cpanel): int
    {
        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $synced = 0;
        $failed = 0;
        // A failure this run is excluded from further batches this same run (it'll still be
        // picked up next tick) - otherwise a persistent failure (bad token, host down) would
        // just spin the same handful of records for the whole time budget instead of resting
        // until the next tick.
        $attemptedIds = [];

        while (now()->lt($deadline)) {
            $batch = GatewayBlockedIp::query()
                ->where('note', 'like', 'Tor exit-node bulk block%')
                ->where('is_active', true)
                ->whereNull('cpanel_synced_at')
                ->whereNotIn('id', $attemptedIds)
                ->oldest('updated_at')
                ->limit(self::BATCH_SIZE)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            $cpanel->blockMany($batch);

            foreach ($batch as $record) {
                $attemptedIds[] = $record->id;
                $record->cpanel_synced_at ? $synced++ : $failed++;
            }
        }

        if ($synced > 0 || $failed > 0) {
            $this->info("Synced {$synced} Tor exit-node block(s) to cPanel".($failed > 0 ? ", {$failed} failed (retried next tick)." : '.'));
        }

        return self::SUCCESS;
    }
}
