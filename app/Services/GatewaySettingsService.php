<?php

namespace App\Services;

use App\Models\Setting;

class GatewaySettingsService
{
    private const KEY_ALLOWED_ORIGINS = 'gateway_allowed_origins';
    private const KEY_GLOBAL_DAILY_SECONDS = 'gateway_global_daily_seconds';
    private const KEY_GLOBAL_DAILY_IP_LIMIT = 'gateway_global_daily_ip_limit';
    private const KEY_GLOBAL_DAILY_TARGET_LIMIT = 'gateway_global_daily_target_limit';
    private const KEY_AUTO_BLOCK_ENABLED = 'gateway_auto_block_enabled';
    private const KEY_AUTO_BLOCK_THRESHOLD = 'gateway_auto_block_threshold';
    private const KEY_AUTO_BLOCK_BASE_HOURS = 'gateway_auto_block_base_hours';
    private const KEY_AUTO_BLOCK_MULTIPLIER = 'gateway_auto_block_multiplier';
    private const KEY_AUTO_BLOCK_MAX_HOURS = 'gateway_auto_block_max_hours';

    private const DEFAULT_ALLOWED_ORIGINS = ['https://smm.plus', 'https://www.smm.plus'];
    private const DEFAULT_GLOBAL_DAILY_SECONDS = 24 * 60 * 60;
    private const DEFAULT_GLOBAL_DAILY_IP_LIMIT = 10;
    private const DEFAULT_GLOBAL_DAILY_TARGET_LIMIT = 10;
    private const DEFAULT_AUTO_BLOCK_ENABLED = false;
    private const DEFAULT_AUTO_BLOCK_THRESHOLD = 500;
    private const DEFAULT_AUTO_BLOCK_BASE_HOURS = 1;
    private const DEFAULT_AUTO_BLOCK_MULTIPLIER = 2;
    private const DEFAULT_AUTO_BLOCK_MAX_HOURS = 168;

    /**
     * @return array<int,string>
     */
    public function getAllowedOrigins(): array
    {
        $stored = $this->get(self::KEY_ALLOWED_ORIGINS);
        if ($stored === null) {
            return self::DEFAULT_ALLOWED_ORIGINS;
        }

        return json_decode($stored, true) ?: [];
    }

    /**
     * @param  array<int,string>  $origins
     */
    public function setAllowedOrigins(array $origins): void
    {
        $origins = array_values(array_unique(array_filter(array_map('trim', $origins))));
        $this->set(self::KEY_ALLOWED_ORIGINS, json_encode($origins));
    }

    public function getGlobalDailySeconds(): int
    {
        $stored = $this->get(self::KEY_GLOBAL_DAILY_SECONDS);

        return $stored !== null ? (int) $stored : self::DEFAULT_GLOBAL_DAILY_SECONDS;
    }

    public function getGlobalDailyIpLimit(): int
    {
        $stored = $this->get(self::KEY_GLOBAL_DAILY_IP_LIMIT);

        return $stored !== null ? (int) $stored : self::DEFAULT_GLOBAL_DAILY_IP_LIMIT;
    }

    public function getGlobalDailyTargetLimit(): int
    {
        $stored = $this->get(self::KEY_GLOBAL_DAILY_TARGET_LIMIT);

        return $stored !== null ? (int) $stored : self::DEFAULT_GLOBAL_DAILY_TARGET_LIMIT;
    }

    public function setGlobalDailyLimits(int $seconds, int $ipLimit, int $targetLimit): void
    {
        $this->set(self::KEY_GLOBAL_DAILY_SECONDS, (string) $seconds);
        $this->set(self::KEY_GLOBAL_DAILY_IP_LIMIT, (string) $ipLimit);
        $this->set(self::KEY_GLOBAL_DAILY_TARGET_LIMIT, (string) $targetLimit);
    }

    public function isAutoBlockEnabled(): bool
    {
        $stored = $this->get(self::KEY_AUTO_BLOCK_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_AUTO_BLOCK_ENABLED;
    }

    public function getAutoBlockThreshold(): int
    {
        $stored = $this->get(self::KEY_AUTO_BLOCK_THRESHOLD);

        return $stored !== null ? (int) $stored : self::DEFAULT_AUTO_BLOCK_THRESHOLD;
    }

    public function getAutoBlockBaseHours(): float
    {
        $stored = $this->get(self::KEY_AUTO_BLOCK_BASE_HOURS);

        return $stored !== null ? (float) $stored : self::DEFAULT_AUTO_BLOCK_BASE_HOURS;
    }

    public function getAutoBlockMultiplier(): float
    {
        $stored = $this->get(self::KEY_AUTO_BLOCK_MULTIPLIER);

        return $stored !== null ? (float) $stored : self::DEFAULT_AUTO_BLOCK_MULTIPLIER;
    }

    public function getAutoBlockMaxHours(): float
    {
        $stored = $this->get(self::KEY_AUTO_BLOCK_MAX_HOURS);

        return $stored !== null ? (float) $stored : self::DEFAULT_AUTO_BLOCK_MAX_HOURS;
    }

    public function setAutoBlockSettings(bool $enabled, int $threshold, float $baseHours, float $multiplier, float $maxHours): void
    {
        $this->set(self::KEY_AUTO_BLOCK_ENABLED, $enabled ? '1' : '0');
        $this->set(self::KEY_AUTO_BLOCK_THRESHOLD, (string) $threshold);
        $this->set(self::KEY_AUTO_BLOCK_BASE_HOURS, (string) $baseHours);
        $this->set(self::KEY_AUTO_BLOCK_MULTIPLIER, (string) $multiplier);
        $this->set(self::KEY_AUTO_BLOCK_MAX_HOURS, (string) $maxHours);
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
