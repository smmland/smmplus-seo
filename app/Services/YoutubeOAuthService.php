<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * There's no public API that answers "is user X subscribed to channel Y" - only the user's own
 * OAuth-authorized token can (subscriptions.list?mine=true), so this is a full three-step OAuth
 * round trip rather than a simple API call: build the consent URL, exchange the callback code for
 * a token, then use that token once to check the subscription. Plain Http facade, no Google SDK -
 * same idiom as TelegramBotService/TelegramContentAiService elsewhere in this app.
 */
class YoutubeOAuthService
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SUBSCRIPTIONS_URL = 'https://www.googleapis.com/youtube/v3/subscriptions';

    private const SCOPE = 'https://www.googleapis.com/auth/youtube.readonly';

    public function __construct(
        private readonly GiveawaySettingsService $settings,
    ) {}

    public function buildConsentUrl(string $redirectUri, string $state): ?string
    {
        $clientId = $this->settings->getGoogleClientId();

        if (! $clientId) {
            return null;
        }

        $query = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => self::SCOPE,
            'access_type' => 'online',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return self::AUTH_URL.'?'.$query;
    }

    /**
     * Exchanges the callback's authorization code for an access token, then immediately checks
     * whether that token's account is subscribed to the configured channel - the token is only
     * ever used for this one call, never stored.
     *
     * @return array{ok: bool, message: string, isSubscribed?: bool, googleAccountId?: string}
     */
    public function verifySubscription(string $code, string $redirectUri): array
    {
        $clientId = $this->settings->getGoogleClientId();
        $clientSecret = $this->settings->getGoogleClientSecret();
        $channelId = $this->settings->getYoutubeChannelId();

        if (! $clientId || ! $clientSecret || ! $channelId) {
            return ['ok' => false, 'message' => 'Google OAuth / YouTube channel not configured.'];
        }

        try {
            $tokenResponse = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
                'code' => $code,
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'redirect_uri' => $redirectUri,
                'grant_type' => 'authorization_code',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error while exchanging code: '.$e->getMessage()];
        }

        if (! $tokenResponse->successful() || ! $tokenResponse->json('access_token')) {
            return ['ok' => false, 'message' => 'Token exchange failed: '.$this->errorDetail($tokenResponse)];
        }

        $accessToken = $tokenResponse->json('access_token');

        try {
            $subsResponse = Http::withToken($accessToken)->timeout(15)->get(self::SUBSCRIPTIONS_URL, [
                'part' => 'id',
                'mine' => 'true',
                'forChannelId' => $channelId,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error while checking subscription: '.$e->getMessage()];
        }

        if (! $subsResponse->successful()) {
            return ['ok' => false, 'message' => 'Subscription check failed: '.$this->errorDetail($subsResponse)];
        }

        $isSubscribed = ! empty($subsResponse->json('items'));

        $googleAccountId = $this->resolveGoogleAccountId($accessToken);

        return ['ok' => true, 'message' => 'ok', 'isSubscribed' => $isSubscribed, 'googleAccountId' => $googleAccountId];
    }

    /**
     * The subscriptions endpoint alone doesn't identify the account - a lightweight extra call so
     * platform_account_id (the giveaway_claims uniqueness key) is a real, stable identifier rather
     * than something derived from the subscription check itself.
     */
    private function resolveGoogleAccountId(string $accessToken): ?string
    {
        try {
            $response = Http::withToken($accessToken)->timeout(15)
                ->get('https://www.googleapis.com/oauth2/v3/userinfo');
        } catch (\Throwable) {
            return null;
        }

        return $response->successful() ? $response->json('sub') : null;
    }

    private function errorDetail(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('error_description') ?? $response->json('error.message') ?? $response->body();

        return 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 300, '…');
    }
}
