<?php

namespace App\Console\Commands;

use App\Models\GatewayRequestLog;
use Illuminate\Console\Command;

class PruneGatewayLogsCommand extends Command
{
    protected $signature = 'gateway:prune-logs {--days=90 : Delete request log rows older than this many days}';

    protected $description = 'Delete old Free Service Gateway request log rows so the table does not grow unbounded';

    public function handle(): int
    {
        $days = (int) $this->option('days');

        $deleted = GatewayRequestLog::query()
            ->where('created_at', '<', now()->subDays($days))
            ->delete();

        $this->info("Deleted {$deleted} gateway request log rows older than {$days} days.");

        return self::SUCCESS;
    }
}
