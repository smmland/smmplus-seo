<?php

namespace App\Services;

use App\Models\GiveawayClaim;
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

    private const KEY_TRUSTPILOT_ENABLED = 'giveaway_trustpilot_enabled';

    private const KEY_TELEGRAM_BOT_USERNAME = 'giveaway_telegram_bot_username';

    private const KEY_YOUTUBE_CHANNEL_ID = 'giveaway_youtube_channel_id';

    // The other two YouTube tasks (featured channel, made a video) are checked with a public
    // Google API key, not OAuth - a channel's featured-channels list and a video's own metadata
    // are both public data, no user consent needed to read them. See YoutubeDataApiService.
    private const KEY_YOUTUBE_FEATURED_ENABLED = 'giveaway_youtube_featured_enabled';

    private const KEY_YOUTUBE_VIDEO_ENABLED = 'giveaway_youtube_video_enabled';

    private const KEY_YOUTUBE_DATA_API_KEY = 'giveaway_youtube_data_api_key';

    // What has to appear in a submitted video's title/description for it to count - e.g. a
    // hashtag or the brand name, so a random unrelated video can't be submitted as proof.
    private const KEY_YOUTUBE_VIDEO_REQUIRED_KEYWORD = 'giveaway_youtube_video_required_keyword';

    // Reward amounts are informational only (reward delivery itself is still manual - see
    // GiveawayClaims) - shown to the admin as a pre-filled hint when marking a claim rewarded,
    // one figure per YouTube task since the user wants each to pay a different amount.
    private const KEY_YOUTUBE_SUBSCRIBE_REWARD_AMOUNT = 'giveaway_youtube_subscribe_reward_amount';

    private const KEY_YOUTUBE_FEATURED_REWARD_AMOUNT = 'giveaway_youtube_featured_reward_amount';

    private const KEY_YOUTUBE_VIDEO_REWARD_AMOUNT = 'giveaway_youtube_video_reward_amount';

    private const KEY_TRUSTPILOT_REVIEW_URL = 'giveaway_trustpilot_review_url';

    private const KEY_GOOGLE_CLIENT_ID = 'giveaway_google_client_id';

    private const KEY_GOOGLE_CLIENT_SECRET = 'giveaway_google_client_secret';

    private const KEY_FRONTEND_RETURN_URL = 'giveaway_frontend_return_url';

    private const KEY_API_BASE_URL = 'giveaway_api_base_url';

    private const DEFAULT_TELEGRAM_ENABLED = false;

    private const DEFAULT_YOUTUBE_ENABLED = false;

    private const DEFAULT_YOUTUBE_FEATURED_ENABLED = false;

    private const DEFAULT_YOUTUBE_VIDEO_ENABLED = false;

    private const DEFAULT_TRUSTPILOT_ENABLED = false;

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

    public function isYoutubeFeaturedEnabled(): bool
    {
        $stored = $this->get(self::KEY_YOUTUBE_FEATURED_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_YOUTUBE_FEATURED_ENABLED;
    }

    public function setYoutubeFeaturedEnabled(bool $enabled): void
    {
        $this->set(self::KEY_YOUTUBE_FEATURED_ENABLED, $enabled ? '1' : '0');
    }

    public function isYoutubeVideoEnabled(): bool
    {
        $stored = $this->get(self::KEY_YOUTUBE_VIDEO_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_YOUTUBE_VIDEO_ENABLED;
    }

    public function setYoutubeVideoEnabled(bool $enabled): void
    {
        $this->set(self::KEY_YOUTUBE_VIDEO_ENABLED, $enabled ? '1' : '0');
    }

    public function hasYoutubeDataApiKey(): bool
    {
        return $this->get(self::KEY_YOUTUBE_DATA_API_KEY) !== null;
    }

    public function getYoutubeDataApiKey(): ?string
    {
        $encrypted = $this->get(self::KEY_YOUTUBE_DATA_API_KEY);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    // Same "blank means keep the existing secret" rule as the Google OAuth client secret below.
    public function setYoutubeDataApiKey(?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $this->set(self::KEY_YOUTUBE_DATA_API_KEY, Crypt::encryptString($key));
    }

    public function getYoutubeVideoRequiredKeyword(): ?string
    {
        return $this->get(self::KEY_YOUTUBE_VIDEO_REQUIRED_KEYWORD);
    }

    public function setYoutubeVideoRequiredKeyword(?string $keyword): void
    {
        $this->set(self::KEY_YOUTUBE_VIDEO_REQUIRED_KEYWORD, trim((string) $keyword));
    }

    public function getYoutubeSubscribeRewardAmount(): ?float
    {
        $stored = $this->get(self::KEY_YOUTUBE_SUBSCRIBE_REWARD_AMOUNT);

        return $stored !== null && $stored !== '' ? (float) $stored : null;
    }

    public function setYoutubeSubscribeRewardAmount(?float $amount): void
    {
        $this->set(self::KEY_YOUTUBE_SUBSCRIBE_REWARD_AMOUNT, $amount !== null ? (string) $amount : '');
    }

    public function getYoutubeFeaturedRewardAmount(): ?float
    {
        $stored = $this->get(self::KEY_YOUTUBE_FEATURED_REWARD_AMOUNT);

        return $stored !== null && $stored !== '' ? (float) $stored : null;
    }

    public function setYoutubeFeaturedRewardAmount(?float $amount): void
    {
        $this->set(self::KEY_YOUTUBE_FEATURED_REWARD_AMOUNT, $amount !== null ? (string) $amount : '');
    }

    public function getYoutubeVideoRewardAmount(): ?float
    {
        $stored = $this->get(self::KEY_YOUTUBE_VIDEO_REWARD_AMOUNT);

        return $stored !== null && $stored !== '' ? (float) $stored : null;
    }

    public function setYoutubeVideoRewardAmount(?float $amount): void
    {
        $this->set(self::KEY_YOUTUBE_VIDEO_REWARD_AMOUNT, $amount !== null ? (string) $amount : '');
    }

    // One lookup point for GiveawayClaims' "Mark as rewarded" modal to pre-fill its note with the
    // configured amount for whichever platform the claim being rewarded is - keyed by
    // GiveawayClaim::PLATFORM_* value so it stays in sync automatically as tasks are added.
    public function getRewardAmountFor(string $platform): ?float
    {
        return match ($platform) {
            GiveawayClaim::PLATFORM_YOUTUBE_SUBSCRIBE => $this->getYoutubeSubscribeRewardAmount(),
            GiveawayClaim::PLATFORM_YOUTUBE_FEATURED => $this->getYoutubeFeaturedRewardAmount(),
            GiveawayClaim::PLATFORM_YOUTUBE_VIDEO => $this->getYoutubeVideoRewardAmount(),
            default => null,
        };
    }

    public function isTrustpilotEnabled(): bool
    {
        $stored = $this->get(self::KEY_TRUSTPILOT_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_TRUSTPILOT_ENABLED;
    }

    public function setTrustpilotEnabled(bool $enabled): void
    {
        $this->set(self::KEY_TRUSTPILOT_ENABLED, $enabled ? '1' : '0');
    }

    // Where the giveaway page sends users to actually write the review - e.g.
    // https://www.trustpilot.com/evaluate/smm.plus. There's no API to auto-verify a review
    // (see GiveawayClaim::PLATFORM_TRUSTPILOT), so this is just the link the "Leave a review"
    // button opens.
    public function getTrustpilotReviewUrl(): ?string
    {
        return $this->get(self::KEY_TRUSTPILOT_REVIEW_URL);
    }

    public function setTrustpilotReviewUrl(?string $url): void
    {
        $this->set(self::KEY_TRUSTPILOT_REVIEW_URL, trim((string) $url));
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
