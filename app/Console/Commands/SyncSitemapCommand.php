<?php

namespace App\Console\Commands;

use App\Models\SyncRun;
use App\Services\SettingsService;
use App\Services\SitemapGeneratorService;
use App\Services\SyncService;
use Illuminate\Console\Command;

class SyncSitemapCommand extends Command
{
    protected $signature = 'sitemap:sync {--force : Run even if the configured interval hasn\'t elapsed yet}';

    protected $description = 'Fetch, classify and store the source sitemap, then regenerate the categorized sitemap files';

    public function handle(SyncService $sync, SitemapGeneratorService $generator, SettingsService $settings): int
    {
        if ($sync->isRunning()) {
            $this->warn('A sync is already in progress, skipping.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->intervalElapsed($settings)) {
            $this->info('Sync interval has not elapsed yet, skipping. Use --force to run anyway.');

            return self::SUCCESS;
        }

        $this->info('Starting sitemap sync...');
        $result = $sync->runSync();
        $generator->generate();

        $this->info("Done: fetched={$result['total_fetched']} added={$result['added']} updated={$result['updated']} removed={$result['removed']}");

        return self::SUCCESS;
    }

    /**
     * The schedule entry runs this command frequently; this gate is what makes the
     * admin-configurable interval (stored in the settings table) actually take effect
     * without needing to touch the crontab or restart anything.
     */
    private function intervalElapsed(SettingsService $settings): bool
    {
        $lastRun = SyncRun::query()
            ->where('status', SyncRun::SUCCESS)
            ->orderByDesc('started_at')
            ->first();

        if (! $lastRun) {
            return true;
        }

        $intervalHours = $settings->getSyncIntervalHours();

        return $lastRun->started_at->addHours($intervalHours)->isPast();
    }
}
