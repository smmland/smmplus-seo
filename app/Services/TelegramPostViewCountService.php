<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Reads the counter rendered by Telegram's public, read-only post embed. The Bot API cannot
 * re-fetch an older channel message just to obtain its current view count; using the public
 * permalink keeps this feature deployable on shared hosting without storing MTProto user
 * credentials. The host is fixed and the channel username is strictly validated, preventing
 * this server-side request from becoming an SSRF primitive.
 */
class TelegramPostViewCountService
{
    public function get(string $channelUsername, int $messageId): int
    {
        $username = ltrim(trim($channelUsername), '@');

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{4,31}$/', $username)) {
            throw new RuntimeException('The configured Telegram channel must have a valid public @username.');
        }

        if ($messageId < 1) {
            throw new RuntimeException('The Telegram message id is invalid.');
        }

        $response = Http::timeout(15)
            ->retry(2, 300)
            ->withHeaders(['Accept' => 'text/html', 'User-Agent' => 'SMMPlus-TelegramViewChecker/1.0'])
            ->get("https://t.me/{$username}/{$messageId}", ['embed' => '1', 'mode' => 'tme']);

        if (! $response->successful()) {
            throw new RuntimeException("Telegram returned HTTP {$response->status()} while reading the post.");
        }

        $body = $response->body();

        if (strlen($body) > 2_000_000) {
            throw new RuntimeException('Telegram returned an unexpectedly large post page.');
        }

        if (! preg_match('/class="[^"]*tgme_widget_message_views[^"]*"[^>]*>\s*([^<]+)\s*</i', $body, $match)) {
            throw new RuntimeException('The public Telegram post or its view counter could not be found.');
        }

        return $this->parseCounter(html_entity_decode(trim($match[1]), ENT_QUOTES | ENT_HTML5));
    }

    public function parseCounter(string $value): int
    {
        $normalized = strtoupper(str_replace(["\u{00A0}", ' '], '', trim($value)));

        if (! preg_match('/^([0-9]+(?:[.,][0-9]+)?)([KMB])?$/', $normalized, $match)) {
            throw new RuntimeException("Telegram returned an unrecognized view count: {$value}");
        }

        $number = (float) str_replace(',', '.', $match[1]);
        $multiplier = match ($match[2] ?? '') {
            'K' => 1_000,
            'M' => 1_000_000,
            'B' => 1_000_000_000,
            default => 1,
        };

        return (int) round($number * $multiplier);
    }
}
