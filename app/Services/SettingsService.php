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
    private const KEY_ACCENT_COLOR = 'accent_color';

    private const DEFAULT_ACCENT_COLOR = 'signal-blue';

    // Curated, not a free color picker - keeps every option legible against both the panel's
    // neutrals and its semantic colors (success/danger badges etc, which stay fixed regardless
    // of accent so they're never confused with it).
    public const ACCENT_COLOR_PRESETS = [
        'teal' => ['label' => 'Teal', 'hex' => '#14B8C6'],
        'signal-blue' => ['label' => 'Signal Blue', 'hex' => '#2F6FED'],
        'indigo' => ['label' => 'Indigo', 'hex' => '#6A5CF5'],
        'violet' => ['label' => 'Violet', 'hex' => '#8B3FE8'],
        'emerald' => ['label' => 'Emerald', 'hex' => '#0F9D6C'],
        'amber' => ['label' => 'Amber', 'hex' => '#D97F0A'],
    ];

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

    public function getAccentColorKey(): string
    {
        $stored = $this->get(self::KEY_ACCENT_COLOR);

        return ($stored !== null && isset(self::ACCENT_COLOR_PRESETS[$stored]))
            ? $stored
            : self::DEFAULT_ACCENT_COLOR;
    }

    public function getAccentColorHex(): string
    {
        return self::ACCENT_COLOR_PRESETS[$this->getAccentColorKey()]['hex'];
    }

    public function setAccentColor(string $key): void
    {
        if (! isset(self::ACCENT_COLOR_PRESETS[$key])) {
            throw new InvalidArgumentException("Unknown accent color preset: {$key}");
        }

        $this->set(self::KEY_ACCENT_COLOR, $key);
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
