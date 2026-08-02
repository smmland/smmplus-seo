<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

class SettingsService
{
    private const KEY_SYNC_INTERVAL_HOURS = 'sync_interval_hours';
    private const KEY_SOURCE_SITEMAP_URL = 'source_sitemap_url';
    private const KEY_CRON_HEARTBEAT_AT = 'cron_heartbeat_at';

    public function getSyncIntervalHours(): int
    {
        $stored = $this->get(self::KEY_SYNC_INTERVAL_HOURS);
        if ($stored !== null) {
            return (int) $stored;
        }

        return (int) config('sitemap.default_sync_interval_hours');
    }

    public function setSyncIntervalHours(int $hours): void
    {
        if ($hours <= 0) {
            throw new InvalidArgumentException('Sync interval must be a positive number of hours');
        }
        $this->set(self::KEY_SYNC_INTERVAL_HOURS, (string) $hours);
    }

    public function getSourceSitemapUrl(): string
    {
        return $this->get(self::KEY_SOURCE_SITEMAP_URL) ?? config('sitemap.source_sitemap_url');
    }

    public function setSourceSitemapUrl(string $url): void
    {
        $this->set(self::KEY_SOURCE_SITEMAP_URL, $url);
    }

    /**
     * Written every minute by a trivial scheduled closure (routes/console.php) - its sole
     * purpose is proving the server's real system crontab is actually invoking
     * `php artisan schedule:run`, since that's a common silent-failure point (wrong PHP path,
     * cron entry missing/disabled, ...) that's otherwise invisible without shell access.
     */
    public function recordCronHeartbeat(): void
    {
        $this->set(self::KEY_CRON_HEARTBEAT_AT, now()->toISOString());
    }

    public function getCronHeartbeatAt(): ?Carbon
    {
        $stored = $this->get(self::KEY_CRON_HEARTBEAT_AT);

        return $stored ? Carbon::parse($stored) : null;
    }

    private function get(string $key): ?string
    {
        return Setting::query()->find($key)?->value;
    }

    private function set(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
