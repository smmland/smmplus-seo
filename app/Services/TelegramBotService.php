<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class TelegramBotService
{
    // Telegram's own limits - captions on a photo are much shorter than a plain text message.
    private const CAPTION_MAX_LENGTH = 1024;

    private const MESSAGE_MAX_LENGTH = 4096;

    private const REQUEST_TIMEOUT_SECONDS = 30;

    public function __construct(private readonly TelegramSettingsService $settings) {}

    /**
     * Uploads the image directly (multipart), not by URL - sidesteps needing this panel's
     * storage to be publicly reachable at all. $imagePath is a path on the 'public' disk, same
     * as everywhere else local images are stored in this app (BlogContentExtractionService,
     * TelegramImageAiService).
     *
     * @return array{ok: bool, message: string}
     */
    public function sendPhoto(string $imagePath, string $caption): array
    {
        [$token, $chatId, $error] = $this->credentials();

        if ($error) {
            return ['ok' => false, 'message' => $error];
        }

        if (! Storage::disk('public')->exists($imagePath)) {
            return ['ok' => false, 'message' => "Image file not found on disk: {$imagePath}"];
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->attach('photo', Storage::disk('public')->get($imagePath), basename($imagePath))
                ->post("https://api.telegram.org/bot{$token}/sendPhoto", [
                    'chat_id' => $chatId,
                    'caption' => mb_strimwidth($caption, 0, self::CAPTION_MAX_LENGTH, '…'),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        return $this->parseResponse($response);
    }

    /**
     * Fallback for a post with no image at all (image generation disabled and no article image
     * found) - never used just because sendPhoto() failed, that's a real error to surface, not
     * silently retried as a text-only post.
     *
     * @return array{ok: bool, message: string}
     */
    public function sendMessage(string $text): array
    {
        [$token, $chatId, $error] = $this->credentials();

        if ($error) {
            return ['ok' => false, 'message' => $error];
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/sendMessage", [
                    'chat_id' => $chatId,
                    'text' => mb_strimwidth($text, 0, self::MESSAGE_MAX_LENGTH, '…'),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        return $this->parseResponse($response);
    }

    /**
     * Validates the bot token via Telegram's own lightweight `getMe` endpoint - real auth check,
     * doesn't touch the channel at all, for the settings page's "Test connection" button.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(?string $tokenOverride = null): array
    {
        $token = $tokenOverride ?: $this->settings->getBotToken();

        if (! $token) {
            return ['ok' => false, 'message' => 'No bot token to test - type one in first.'];
        }

        try {
            $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getMe");
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful() || ! $response->json('ok')) {
            return ['ok' => false, 'message' => $this->errorMessage($response)];
        }

        $username = $response->json('result.username');

        return ['ok' => true, 'message' => $username ? "Connected as @{$username}." : 'Connected.'];
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [token, chatId, errorMessage]
     */
    private function credentials(): array
    {
        if (! $this->settings->isEnabled()) {
            return [null, null, 'Telegram integration is disabled - enable it in Telegram Settings first.'];
        }

        $token = $this->settings->getBotToken();
        $chatId = $this->settings->getChannelId();

        if (! $token || ! $chatId) {
            return [null, null, 'No bot token or channel id configured - set them up in Telegram Settings first.'];
        }

        return [$token, $chatId, null];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful() && $response->json('ok')) {
            return ['ok' => true, 'message' => 'Sent.'];
        }

        return ['ok' => false, 'message' => $this->errorMessage($response)];
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('description') ?? $response->body();

        return 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 300, '…');
    }
}
