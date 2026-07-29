<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Runs every 15 minutes but the command itself gates on the admin-configurable
// interval (Setting: sync_interval_hours), so changing it in the panel takes effect
// without touching this file or the server crontab.
Schedule::command('sitemap:sync')->everyFifteenMinutes()->withoutOverlapping();

// Keeps the gateway request log (one row per free-service request) from growing
// unbounded on a busy gateway.
Schedule::command('gateway:prune-logs')->daily();
