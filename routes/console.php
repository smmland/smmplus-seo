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

// Processes queued blog translation jobs, up to the configured number (AI Settings: Max
// concurrent translations, default 3) fired at the AI provider together - this shared host has
// no persistent worker process, so this rides the same once-a-minute schedule:run tick
// everything else here does, draining as many batches as fit in its own time budget rather than
// waiting for the next tick. withoutOverlapping() covers a batch that runs long enough for the
// next tick to fire while this one's still busy. Skipped entirely while a panel update is
// installing (GeneralSettings::installUpdate) - the update overwrites this app's own files, and
// a translation picked up mid-swap could run against a half-replaced codebase.
Schedule::command('translation:process-queue')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->skip(fn () => app(SettingsService::class)->isPanelUpdateInProgress());

// Extracts content (and queues AI translation, if also enabled) for newly discovered
// default-language blog topics - both gated on Translation Settings: "New blog topics", off by
// default. A no-op query when auto-extract is off, so this can just always be scheduled rather
// than conditionally registered. Skipped mid panel-update for the same reason
// translation:process-queue is: the update overwrites this app's own files.
Schedule::command('blog:auto-process-new')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->skip(fn () => app(SettingsService::class)->isPanelUpdateInProgress());

// Re-syncs the services catalog (all services live on one shared /services page per language,
// unlike blog's one-URL-per-article) and auto-queues missing translations, twice a day - the
// admin only asked for a ~12 hour cadence here, nowhere near blog's hourly recheck.
Schedule::command('services:refresh-catalog')->twiceDaily(3, 15)->withoutOverlapping();

// Same reasoning as translation:process-queue: no persistent worker on this host, so this rides
// the once-a-minute schedule:run tick, draining whatever's queued up to the configured
// concurrency. Skipped mid panel-update for the same reason.
Schedule::command('services:process-queue')
    ->everyMinute()
    ->withoutOverlapping(20)
    ->skip(fn () => app(SettingsService::class)->isPanelUpdateInProgress());
