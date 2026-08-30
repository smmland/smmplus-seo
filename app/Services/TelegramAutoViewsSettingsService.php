<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Settings for automatically ordering views on every Telegram post once it's actually sent
 * (TelegramPostViewsService), via one of the same upstream SMM providers the Free Service Gateway
 * already talks to. Deliberately its own settings namespace, entirely separate from GatewayService
 * (the public-facing free-service catalog) - the admin picks the upstream and types the service id
 * directly here rather than adding a GatewayService row, specifically so this never becomes
 * orderable through the public /api/free-service/order endpoint the way a catalog entry would be.
 */
class TelegramAutoViewsSettingsService
{
    private const KEY_ENABLED = 'telegram_auto_views_enabled';
    private const KEY_UPSTREAM_ID = 'telegram_auto_views_upstream_id';
    private const KEY_SERVICE_ID = 'telegram_auto_views_service_id';
    private const KEY_TARGET = 'telegram_auto_views_target';
    private const KEY_LOOKBACK_DAYS = 'telegram_auto_views_lookback_days';
    private const KEY_COOLDOWN_HOURS = 'telegram_auto_views_cooldown_hours';
    private const KEY_MAX_POSTS_PER_RUN = 'telegram_auto_views_max_posts_per_run';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_TARGET = 500;
    private const DEFAULT_LOOKBACK_DAYS = 30;
    private const DEFAULT_COOLDOWN_HOURS = 12;
    private const DEFAULT_MAX_POSTS_PER_RUN = 20;

    public function isEnabled(): bool
    {
        $stored = $this->get(self::KEY_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_ENABLED;
    }

    public function getUpstreamId(): ?int
    {
        $stored = $this->get(self::KEY_UPSTREAM_ID);

        return $stored ? (int) $stored : null;
    }

    public function getServiceId(): ?string
    {
        return $this->get(self::KEY_SERVICE_ID) ?: null;
    }

    public function getTarget(): int
    {
        $stored = $this->get(self::KEY_TARGET);

        return $stored !== null ? max(1, (int) $stored) : self::DEFAULT_TARGET;
    }

    public function getLookbackDays(): int
    {
        return max(1, min(365, (int) ($this->get(self::KEY_LOOKBACK_DAYS) ?? self::DEFAULT_LOOKBACK_DAYS)));
    }

    public function getCooldownHours(): int
    {
        return max(1, min(168, (int) ($this->get(self::KEY_COOLDOWN_HOURS) ?? self::DEFAULT_COOLDOWN_HOURS)));
    }

    public function getMaxPostsPerRun(): int
    {
        return max(1, min(100, (int) ($this->get(self::KEY_MAX_POSTS_PER_RUN) ?? self::DEFAULT_MAX_POSTS_PER_RUN)));
    }

    public function setSettings(
        bool $enabled,
        ?int $upstreamId,
        ?string $serviceId,
        int $target,
        int $lookbackDays = self::DEFAULT_LOOKBACK_DAYS,
        int $cooldownHours = self::DEFAULT_COOLDOWN_HOURS,
        int $maxPostsPerRun = self::DEFAULT_MAX_POSTS_PER_RUN,
    ): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
        $this->set(self::KEY_UPSTREAM_ID, $upstreamId !== null ? (string) $upstreamId : '');
        $this->set(self::KEY_SERVICE_ID, trim((string) $serviceId));
        $this->set(self::KEY_TARGET, (string) max(1, $target));
        $this->set(self::KEY_LOOKBACK_DAYS, (string) max(1, min(365, $lookbackDays)));
        $this->set(self::KEY_COOLDOWN_HOURS, (string) max(1, min(168, $cooldownHours)));
        $this->set(self::KEY_MAX_POSTS_PER_RUN, (string) max(1, min(100, $maxPostsPerRun)));
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
