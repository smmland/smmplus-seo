<?php

namespace App\Console\Commands;

use App\Services\TorExitNodeListService;
use Illuminate\Console\Command;

class RefreshTorExitNodeListCommand extends Command
{
    protected $signature = 'security:refresh-tor-exit-list';

    protected $description = 'Refreshes the cached list of Tor exit node IPs used to block gateway abuse routed through Tor';

    public function handle(TorExitNodeListService $list): int
    {
        $count = $list->refresh();

        $this->info("Tor exit node list refreshed: {$count} IPs.");

        return self::SUCCESS;
    }
}
