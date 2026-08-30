<?php

namespace App\Console\Commands;

use App\Models\AnalyticsEvent;
use Illuminate\Console\Command;

class PruneAnalyticsEventsCommand extends Command
{
    protected $signature = 'analytics:prune {--days=180 : Delete analytics events older than this many days}';

    protected $description = 'Delete old first-party website analytics events';

    public function handle(): int
    {
        $days = max(30, (int) $this->option('days'));
        $deleted = AnalyticsEvent::query()
            ->where('occurred_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} analytics events older than {$days} days.");

        return self::SUCCESS;
    }
}
