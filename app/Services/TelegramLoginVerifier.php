<?php

namespace App\Services;

/**
 * Verifies the payload the official Telegram Login Widget hands back to the page's JS callback,
 * per Telegram's documented check (https://core.telegram.org/widgets/login#checking-authorization):
 * HMAC-SHA256 over the sorted "key=value" fields (everything except `hash` itself), keyed by
 * SHA256(bot_token). This is the only thing standing between "a real Telegram login" and anyone
 * just POSTing a made-up payload to the giveaway API, so it's checked before anything else -
 * including before touching the database at all.
 */
class TelegramLoginVerifier
{
    // How stale a widget auth payload is allowed to be before it's rejected as a possible replay
    // - generous enough to survive normal network/page-load delay, tight enough that a captured
    // payload can't be reused hours or days later.
    private const MAX_AUTH_AGE_SECONDS = 600;

    public function __construct(private readonly TelegramSettingsService $settings) {}

    /**
     * @param  array<string,mixed>  $payload  The widget's onauth callback data (id, first_name,
     *                                        username, auth_date, hash, ...).
     * @return array{ok: bool, message: string, telegramUserId?: string}
     */
    public function verify(array $payload): array
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return ['ok' => false, 'message' => 'No Telegram bot token configured.'];
        }

        $hash = $payload['hash'] ?? null;
        $telegramUserId = $payload['id'] ?? null;

        if (! is_string($hash) || $hash === '' || ! isset($telegramUserId)) {
            return ['ok' => false, 'message' => 'Malformed login payload.'];
        }

        $dataCheckString = $this->buildDataCheckString($payload);
        $secretKey = hash('sha256', $token, true);
        $expectedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (! hash_equals($expectedHash, strtolower($hash))) {
            return ['ok' => false, 'message' => 'Login signature verification failed.'];
        }

        $authDate = (int) ($payload['auth_date'] ?? 0);

        if ($authDate <= 0 || now()->timestamp - $authDate > self::MAX_AUTH_AGE_SECONDS) {
            return ['ok' => false, 'message' => 'Login payload has expired - please try again.'];
        }

        return ['ok' => true, 'message' => 'ok', 'telegramUserId' => (string) $telegramUserId];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function buildDataCheckString(array $payload): string
    {
        $fields = collect($payload)
            ->except('hash')
            ->filter(fn ($value) => $value !== null)
            ->sortKeys()
            ->map(fn ($value, $key) => "{$key}={$value}");

        return $fields->implode("\n");
    }
}
