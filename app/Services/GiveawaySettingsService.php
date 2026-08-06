<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

/**
 * Telegram's own bot token/channel id are deliberately NOT duplicated here - they're reused
 * straight from TelegramSettingsService, since giveaway verification uses the exact same
 * bot/channel already configured for the Telegram Channel feature.
 */
class GiveawaySettingsService
{
    private const KEY_TELEGRAM_ENABLED = 'giveaway_telegram_enabled';

    private const KEY_YOUTUBE_ENABLED = 'giveaway_youtube_enabled';

    private const KEY_TELEGRAM_BOT_USERNAME = 'giveaway_telegram_bot_username';

    private const KEY_YOUTUBE_CHANNEL_ID = 'giveaway_youtube_channel_id';

    private const KEY_GOOGLE_CLIENT_ID = 'giveaway_google_client_id';

    private const KEY_GOOGLE_CLIENT_SECRET = 'giveaway_google_client_secret';

    private const KEY_FRONTEND_RETURN_URL = 'giveaway_frontend_return_url';

    private const KEY_API_BASE_URL = 'giveaway_api_base_url';

    private const DEFAULT_TELEGRAM_ENABLED = false;

    private const DEFAULT_YOUTUBE_ENABLED = false;

    private const DEFAULT_FRONTEND_RETURN_URL = 'https://smm.plus/giveaway';

    // The publicly-reachable domain this app's API is actually served on - NOT necessarily
    // APP_URL (that's seo.smm.plus, used for asset/URL generation inside this app itself). The
    // existing free-service frontend page already calls this exact same Laravel app's API at
    // https://core.smm.plus/api/free-service/order, confirmed by reading that page's own code -
    // so this default matches that proven, working setup rather than guessing.
    private const DEFAULT_API_BASE_URL = 'https://core.smm.plus';

    public function isTelegramEnabled(): bool
    {
        $stored = $this->get(self::KEY_TELEGRAM_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_TELEGRAM_ENABLED;
    }

    public function setTelegramEnabled(bool $enabled): void
    {
        $this->set(self::KEY_TELEGRAM_ENABLED, $enabled ? '1' : '0');
    }

    public function isYoutubeEnabled(): bool
    {
        $stored = $this->get(self::KEY_YOUTUBE_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_YOUTUBE_ENABLED;
    }

    public function setYoutubeEnabled(bool $enabled): void
    {
        $this->set(self::KEY_YOUTUBE_ENABLED, $enabled ? '1' : '0');
    }

    // The giveaway page (giveaway.twig, in the separate smmplus-website repo) can't read this
    // panel's own Settings table directly - it's static Twig rendered by a different, third-party
    // system with no way to inject a new template variable into it. So the page's JS fetches this
    // over /api/giveaway/config at runtime instead (see GiveawayController::config()), and uses it
    // to render the actual Telegram Login Widget (which needs the bot's public @username, not its
    // secret token).
    public function getTelegramBotUsername(): ?string
    {
        return $this->get(self::KEY_TELEGRAM_BOT_USERNAME);
    }

    public function setTelegramBotUsername(?string $username): void
    {
        $this->set(self::KEY_TELEGRAM_BOT_USERNAME, ltrim(trim((string) $username), '@'));
    }

    public function getYoutubeChannelId(): ?string
    {
        return $this->get(self::KEY_YOUTUBE_CHANNEL_ID);
    }

    public function setYoutubeChannelId(?string $channelId): void
    {
        $this->set(self::KEY_YOUTUBE_CHANNEL_ID, trim((string) $channelId));
    }

    public function hasGoogleClientId(): bool
    {
        return $this->get(self::KEY_GOOGLE_CLIENT_ID) !== null;
    }

    public function getGoogleClientId(): ?string
    {
        return $this->get(self::KEY_GOOGLE_CLIENT_ID);
    }

    public function setGoogleClientId(?string $clientId): void
    {
        $this->set(self::KEY_GOOGLE_CLIENT_ID, trim((string) $clientId));
    }

    public function hasGoogleClientSecret(): bool
    {
        return $this->get(self::KEY_GOOGLE_CLIENT_SECRET) !== null;
    }

    public function getGoogleClientSecret(): ?string
    {
        $encrypted = $this->get(self::KEY_GOOGLE_CLIENT_SECRET);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    // Same "blank means keep the existing secret" rule as AiSettingsService::setApiKey() /
    // TelegramSettingsService::setBotToken() - the form field is never pre-filled with the real
    // secret.
    public function setGoogleClientSecret(?string $secret): void
    {
        if ($secret === null || $secret === '') {
            return;
        }

        $this->set(self::KEY_GOOGLE_CLIENT_SECRET, Crypt::encryptString($secret));
    }

    // Where the OAuth callback sends the browser back to (a full page redirect, not a fetch
    // response) after checking the YouTube subscription - the actual giveaway page on the public
    // site, which lives in a separate codebase/domain from this panel.
    public function getFrontendReturnUrl(): string
    {
        return $this->get(self::KEY_FRONTEND_RETURN_URL) ?? self::DEFAULT_FRONTEND_RETURN_URL;
    }

    public function setFrontendReturnUrl(string $url): void
    {
        $this->set(self::KEY_FRONTEND_RETURN_URL, trim($url));
    }

    // Used to build the YouTube OAuth redirect URI (must exactly match what's registered in the
    // Google Cloud console) and told to the giveaway page's JS via /api/giveaway/config so it
    // knows where to send its fetch() calls - deliberately not derived from Laravel's own
    // url()/APP_URL, which point at seo.smm.plus, not the publicly-reachable core.smm.plus.
    public function getApiBaseUrl(): string
    {
        return rtrim($this->get(self::KEY_API_BASE_URL) ?? self::DEFAULT_API_BASE_URL, '/');
    }

    public function setApiBaseUrl(string $url): void
    {
        $this->set(self::KEY_API_BASE_URL, rtrim(trim($url), '/'));
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
