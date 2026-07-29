<?php

namespace App\Services;

use App\Models\Setting;

class TranslationSettingsService
{
    private const KEY_AUTO_HIDE_ENABLED = 'translation_auto_hide_enabled';
    private const KEY_RECHECK_INTERVAL_HOURS = 'translation_recheck_interval_hours';

    private const DEFAULT_AUTO_HIDE_ENABLED = false;
    private const DEFAULT_RECHECK_INTERVAL_HOURS = 12;

    public function isAutoHideEnabled(): bool
    {
        $stored = $this->get(self::KEY_AUTO_HIDE_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_AUTO_HIDE_ENABLED;
    }

    public function getRecheckIntervalHours(): int
    {
        $stored = $this->get(self::KEY_RECHECK_INTERVAL_HOURS);

        return $stored !== null ? (int) $stored : self::DEFAULT_RECHECK_INTERVAL_HOURS;
    }

    public function setSettings(bool $autoHideEnabled, int $recheckIntervalHours): void
    {
        $this->set(self::KEY_AUTO_HIDE_ENABLED, $autoHideEnabled ? '1' : '0');
        $this->set(self::KEY_RECHECK_INTERVAL_HOURS, (string) $recheckIntervalHours);
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
