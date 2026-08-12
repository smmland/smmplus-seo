<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Master on/off switch for the public-facing side of the Reviews feature (GET /api/reviews and,
 * once it exists, the submission endpoint) - independent of the "Approved" flag on individual
 * reviews, which controls per-review visibility. Managing reviews in the panel (ReviewResource)
 * always works regardless of this setting; it only gates what the live site's frontend receives.
 */
class ReviewsSettingsService
{
    private const KEY_ENABLED = 'reviews_enabled';

    private const DEFAULT_ENABLED = true;

    public function isEnabled(): bool
    {
        $stored = $this->get(self::KEY_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_ENABLED;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
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
