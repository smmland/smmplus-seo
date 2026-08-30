<?php

namespace App\Console\Commands;

use App\Services\CatalogSyncService;
use Illuminate\Console\Command;

/**
 * Runs daily (routes/console.php) - re-syncs catalog_services from smm.plus's own customer API
 * (CatalogSyncService), the cache GET /api/services reads from. Manual, immediate counterpart to
 * this scheduled run: the "Sync now" action on the Catalog Services admin page.
 */
class RefreshCatalogServicesCommand extends Command
{
    protected $signature = 'catalog:refresh-services';

    protected $description = 'Re-syncs the cached smm.plus retail service catalog (price/min/max) used by GET /api/services';

    public function handle(CatalogSyncService $sync): int
    {
        $result = $sync->sync();

        if (! $result['ok']) {
            $this->error('Could not sync the catalog: '.$result['error']);

            return self::FAILURE;
        }

        $this->info("Synced {$result['total']} service(s): {$result['added']} new, {$result['changed']} changed, {$result['unavailable']} no longer available, {$result['seededTranslations']} newly seen by the translation pipeline (will be auto-translated into every active language by the next services:refresh-catalog run).");

        return self::SUCCESS;
    }
}
