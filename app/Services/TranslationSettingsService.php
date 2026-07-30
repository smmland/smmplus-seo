<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

class TranslationSettingsService
{
    private const KEY_AUTO_HIDE_ENABLED = 'translation_auto_hide_enabled';
    private const KEY_RECHECK_INTERVAL_HOURS = 'translation_recheck_interval_hours';
    private const KEY_LAST_SCHEDULED_RUN_AT = 'translation_last_scheduled_run_at';

    private const DEFAULT_AUTO_HIDE_ENABLED = false;
    private const DEFAULT_RECHECK_INTERVAL_HOURS = 12;

    // Matches routes/console.php's Schedule::command('translation:refresh-blog-status')->hourly().
    public const SCHEDULE_INTERVAL_MINUTES = 60;

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

    public function getLastScheduledRunAt(): ?Carbon
    {
        $stored = $this->get(self::KEY_LAST_SCHEDULED_RUN_AT);

        return $stored !== null ? Carbon::parse($stored) : null;
    }

    // Called only by the hourly `translation:refresh-blog-status` cron command - not by the
    // "Run now" button - so this timestamp reflects the actual automatic cadence, not manual runs.
    public function recordScheduledRun(): void
    {
        $this->set(self::KEY_LAST_SCHEDULED_RUN_AT, now()->toIso8601String());
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
