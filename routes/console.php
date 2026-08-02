<?php

use App\Services\SettingsService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// A trivial heartbeat, not tied to any real feature: it just proves the server's system
// crontab is actually reaching `php artisan schedule:run` at all, which is what everything
// else on this page depends on. The Settings page reads it back and shows whether it's
// fresh - the only way to tell the cron entry is broken (missing, wrong PHP path, disabled)
// without shell access to the server.
Schedule::call(fn () => app(SettingsService::class)->recordCronHeartbeat())->everyMinute();

// Runs every 15 minutes but the command itself gates on the admin-configurable
// interval (Setting: sync_interval_hours), so changing it in the panel takes effect
// without touching this file or the server crontab.
Schedule::command('sitemap:sync')->everyFifteenMinutes()->withoutOverlapping();

// Keeps the gateway request log (one row per free-service request) from growing
// unbounded on a busy gateway.
Schedule::command('gateway:prune-logs')->daily();

// Detects IPs over the configurable daily request threshold and blocks them with an
// escalating cool-down (gated on Gateway Settings: auto_block_enabled).
Schedule::command('gateway:auto-block-ips')->everyFiveMinutes()->withoutOverlapping();

// Each blog URL is only actually re-checked once its own recheck interval has elapsed
// (Translation Settings), so running this hourly just means newly-published or
// newly-translated posts don't wait long to be picked up.
Schedule::command('translation:refresh-blog-status')->hourly()->withoutOverlapping();

// Processes queued jobs (currently just TranslateBlogArticleJob) - this shared host has no
// persistent `queue:work` process (no SSH access to keep one running), so the queue is drained
// a batch at a time off the same schedule:run tick everything else here rides on instead.
// --stop-when-empty exits once drained rather than idling, so this doesn't overlap the next
// tick; --timeout must be >= TranslateBlogArticleJob's own $timeout (900s) or the worker would
// kill a still-legitimately-running translation itself. withoutOverlapping() covers the case
// where one translation runs long enough that the next tick fires while this one's still busy.
// Skipped entirely while a panel update is installing (GeneralSettings::installUpdate) - the
// update overwrites this app's own files, and a translation job picked up mid-swap could run
// against a half-replaced codebase.
Schedule::command('queue:work --queue=default --stop-when-empty --tries=1 --timeout=900')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->skip(fn () => app(SettingsService::class)->isPanelUpdateInProgress());
