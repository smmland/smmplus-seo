<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Master on/off switch for the public-facing side of the Reviews feature (GET /api/reviews and
 * the submission endpoint) - independent of the "Approved" flag on individual reviews, which
 * controls per-review visibility. Managing reviews in the panel (ReviewResource) always works
 * regardless of this setting; it only gates what the live site's frontend receives.
 *
 * Also carries a second, narrower toggle: which specific pages on the live site show the
 * "leave a review" prompt at all (GET /api/reviews/status?page=... - ReviewsController::status()).
 * A page can be turned off here even while reviews are globally enabled, e.g. to stop prompting
 * on the dashboard without touching the ticket/order-status/refill prompts.
 */
class ReviewsSettingsService
{
    private const KEY_ENABLED = 'reviews_enabled';

    private const DEFAULT_ENABLED = true;

    // The only page identifiers ReviewsController::status() recognizes - an unlisted `page`
    // value always reads as "don't show the prompt" rather than silently defaulting to shown.
    public const PROMPT_PAGES = [
        'ticket_reply' => 'Support ticket (after a reply)',
        'order_status' => 'Order status page',
        'refill' => 'Refill page',
        'dashboard' => 'Dashboard',
    ];

    private const DEFAULT_PROMPT_ENABLED = true;

    public function isEnabled(): bool
    {
        $stored = $this->get(self::KEY_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_ENABLED;
    }

    public function setEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public function isPromptEnabledFor(string $page): bool
    {
        if (! array_key_exists($page, self::PROMPT_PAGES)) {
            return false;
        }

        $stored = $this->get("reviews_prompt_{$page}");

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_PROMPT_ENABLED;
    }

    public function setPromptEnabledFor(string $page, bool $enabled): void
    {
        if (! array_key_exists($page, self::PROMPT_PAGES)) {
            return;
        }

        $this->set("reviews_prompt_{$page}", $enabled ? '1' : '0');
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
