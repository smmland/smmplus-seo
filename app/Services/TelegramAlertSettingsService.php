<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Settings for the personal DM alert system (Telegram Channel > Alerts) - entirely separate from
 * TelegramSettingsService, which covers the channel-posting bot itself. Alerts reuse the same bot
 * token to DM a configurable list of recipients (TelegramAlertRecipient) whenever something the
 * admin would want to know about happens, independent of whether channel posting is even enabled.
 */
class TelegramAlertSettingsService
{
    private const KEY_ENABLED = 'telegram_alerts_enabled';
    private const KEY_ON_NEW_SERVICE = 'telegram_alerts_on_new_service';
    private const KEY_ON_SERVICE_CHANGED = 'telegram_alerts_on_service_changed';
    private const KEY_ON_NEW_TEXT = 'telegram_alerts_on_new_text';
    private const KEY_ON_POST_PREVIEW = 'telegram_alerts_on_post_preview';
    private const KEY_ON_TRANSLATION_COMPLETED = 'telegram_alerts_on_translation_completed';
    private const KEY_ON_ATTACK_DETECTED = 'telegram_alerts_on_attack_detected';
    private const KEY_PREVIEW_MINUTES_BEFORE = 'telegram_alerts_preview_minutes_before';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_ON_NEW_SERVICE = true;
    private const DEFAULT_ON_SERVICE_CHANGED = true;
    private const DEFAULT_ON_NEW_TEXT = true;
    private const DEFAULT_ON_POST_PREVIEW = true;
    private const DEFAULT_ON_TRANSLATION_COMPLETED = true;
    private const DEFAULT_ON_ATTACK_DETECTED = true;
    private const DEFAULT_PREVIEW_MINUTES_BEFORE = 30;

    public function isEnabled(): bool
    {
        return $this->getBool(self::KEY_ENABLED, self::DEFAULT_ENABLED);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public function isOnNewServiceEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_NEW_SERVICE, self::DEFAULT_ON_NEW_SERVICE);
    }

    public function setOnNewServiceEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_NEW_SERVICE, $enabled ? '1' : '0');
    }

    public function isOnServiceChangedEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_SERVICE_CHANGED, self::DEFAULT_ON_SERVICE_CHANGED);
    }

    public function setOnServiceChangedEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_SERVICE_CHANGED, $enabled ? '1' : '0');
    }

    public function isOnNewTextEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_NEW_TEXT, self::DEFAULT_ON_NEW_TEXT);
    }

    public function setOnNewTextEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_NEW_TEXT, $enabled ? '1' : '0');
    }

    public function isOnPostPreviewEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_POST_PREVIEW, self::DEFAULT_ON_POST_PREVIEW);
    }

    public function setOnPostPreviewEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_POST_PREVIEW, $enabled ? '1' : '0');
    }

    public function isOnTranslationCompletedEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_TRANSLATION_COMPLETED, self::DEFAULT_ON_TRANSLATION_COMPLETED);
    }

    public function setOnTranslationCompletedEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_TRANSLATION_COMPLETED, $enabled ? '1' : '0');
    }

    public function isOnAttackDetectedEnabled(): bool
    {
        return $this->getBool(self::KEY_ON_ATTACK_DETECTED, self::DEFAULT_ON_ATTACK_DETECTED);
    }

    public function setOnAttackDetectedEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ON_ATTACK_DETECTED, $enabled ? '1' : '0');
    }

    public function getPreviewMinutesBefore(): int
    {
        $stored = $this->get(self::KEY_PREVIEW_MINUTES_BEFORE);

        return max(1, $stored !== null ? (int) $stored : self::DEFAULT_PREVIEW_MINUTES_BEFORE);
    }

    public function setPreviewMinutesBefore(int $minutes): void
    {
        $this->set(self::KEY_PREVIEW_MINUTES_BEFORE, (string) max(1, $minutes));
    }

    private function getBool(string $key, bool $default): bool
    {
        $stored = $this->get($key);

        return $stored !== null ? (bool) (int) $stored : $default;
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
