<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

class TranslationSettingsService
{
    private const KEY_AUTO_HIDE_ENABLED = 'translation_auto_hide_enabled';
    private const KEY_RECHECK_INTERVAL_HOURS = 'translation_recheck_interval_hours';
    private const KEY_LAST_SCHEDULED_RUN_AT = 'translation_last_scheduled_run_at';
    private const KEY_AUTO_EXTRACT_NEW_BLOGS_ENABLED = 'translation_auto_extract_new_blogs_enabled';
    private const KEY_AUTO_TRANSLATE_NEW_BLOGS_ENABLED = 'translation_auto_translate_new_blogs_enabled';
    private const KEY_SERVICE_LAST_SCHEDULED_RUN_AT = 'translation_service_last_scheduled_run_at';

    private const DEFAULT_AUTO_HIDE_ENABLED = false;
    private const DEFAULT_RECHECK_INTERVAL_HOURS = 12;
    private const DEFAULT_AUTO_EXTRACT_NEW_BLOGS_ENABLED = false;
    private const DEFAULT_AUTO_TRANSLATE_NEW_BLOGS_ENABLED = false;

    // Matches routes/console.php's Schedule::command('translation:refresh-blog-status')->hourly().
    public const SCHEDULE_INTERVAL_MINUTES = 60;

    // Matches routes/console.php's Schedule::command('services:refresh-catalog')->hourly().
    public const SERVICE_SCHEDULE_INTERVAL_MINUTES = 60;

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

    public function isAutoExtractNewBlogsEnabled(): bool
    {
        $stored = $this->get(self::KEY_AUTO_EXTRACT_NEW_BLOGS_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_AUTO_EXTRACT_NEW_BLOGS_ENABLED;
    }

    public function isAutoTranslateNewBlogsEnabled(): bool
    {
        // Auto-translate is meaningless without auto-extract - a topic never auto-extracted has
        // no content_extracted_at, so blog:auto-process-new never gets far enough to queue a
        // translation for it anyway. This just makes that dependency visible from the stored
        // value itself, not only from setAutoProcessSettings()'s write-time enforcement below - a
        // row written before this dependency existed, or edited directly in the settings table,
        // can't silently re-enable auto-translate on its own.
        if (! $this->isAutoExtractNewBlogsEnabled()) {
            return false;
        }

        $stored = $this->get(self::KEY_AUTO_TRANSLATE_NEW_BLOGS_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_AUTO_TRANSLATE_NEW_BLOGS_ENABLED;
    }

    /**
     * Governs blog:auto-process-new (see routes/console.php). Enforced here, not just in the
     * settings form, so a stale/tampered form submission can never turn on auto-translate while
     * auto-extract is off - disabling auto-extract always forces auto-translate off too.
     */
    public function setAutoProcessSettings(bool $autoExtractEnabled, bool $autoTranslateEnabled): void
    {
        $this->set(self::KEY_AUTO_EXTRACT_NEW_BLOGS_ENABLED, $autoExtractEnabled ? '1' : '0');
        $this->set(self::KEY_AUTO_TRANSLATE_NEW_BLOGS_ENABLED, ($autoExtractEnabled && $autoTranslateEnabled) ? '1' : '0');
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

    public function getServiceLastScheduledRunAt(): ?Carbon
    {
        $stored = $this->get(self::KEY_SERVICE_LAST_SCHEDULED_RUN_AT);

        return $stored !== null ? Carbon::parse($stored) : null;
    }

    // The services counterpart to recordScheduledRun() - called only by the hourly
    // `services:refresh-catalog` cron command, not by the Service Translation page's own "Sync
    // now" button.
    public function recordServiceScheduledRun(): void
    {
        $this->set(self::KEY_SERVICE_LAST_SCHEDULED_RUN_AT, now()->toIso8601String());
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
