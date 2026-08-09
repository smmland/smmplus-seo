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
     * @return array{ok: bool, message: string, message_id?: int}
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
     * @return array{ok: bool, message: string, message_id?: int}
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
     * Personal DM counterpart to sendPhoto()/sendMessage() - takes an explicit chat_id instead of
     * the configured channel, and only requires a bot token (not the channel id, not isEnabled())
     * since a personal alert is a different concern from channel posting, same reasoning as
     * getChatMember()/getUpdates() below.
     *
     * @return array{ok: bool, message: string, message_id?: int}
     */
    public function sendMessageTo(string $chatId, string $text): array
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return ['ok' => false, 'message' => 'No bot token configured - set it up in Telegram Settings first.'];
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
     * @return array{ok: bool, message: string, message_id?: int}
     */
    public function sendPhotoTo(string $chatId, string $imagePath, string $caption): array
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return ['ok' => false, 'message' => 'No bot token configured - set it up in Telegram Settings first.'];
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
     * Used to build the t.me/<username>?start=<token> deep link recipients open to link their
     * chat_id - see TelegramAlertRecipient/AlertsSettings.
     */
    public function getBotUsername(): ?string
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return null;
        }

        try {
            $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getMe");
        } catch (\Throwable) {
            return null;
        }

        if (! $response->successful() || ! $response->json('ok')) {
            return null;
        }

        return $response->json('result.username');
    }

    /**
     * Polls for new updates since $offset (Telegram's own cursor convention: passing
     * offset = last_update_id + 1 both fetches only what's new AND tells Telegram those older
     * ones can be dropped from its queue). Short poll (timeout=0) rather than long-polling - this
     * rides the same once-a-minute schedule:run tick every other command here does, so blocking
     * the PHP process waiting on Telegram would just eat into that budget for no benefit.
     * allowed_updates restricts this to channel posts and private messages - the only two kinds
     * TelegramCaptureChannelPostsCommand does anything with (channel-post capture and
     * alert-recipient /start linking). Both share this one offset cursor, so both must always be
     * requested together - passing offset marks every update below it as received by Telegram
     * whether or not it matched allowed_updates, so polling for them separately would risk one
     * silently losing updates the other hasn't processed yet.
     *
     * @return array{ok: bool, message: string, updates?: array}
     */
    public function getUpdates(int $offset, int $limit = 100): array
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return ['ok' => false, 'message' => 'No bot token configured - set it up in Telegram Settings first.'];
        }

        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->asForm()
                ->post("https://api.telegram.org/bot{$token}/getUpdates", [
                    'offset' => $offset,
                    'limit' => $limit,
                    'timeout' => 0,
                    'allowed_updates' => json_encode(['channel_post', 'message']),
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful() || ! $response->json('ok')) {
            return ['ok' => false, 'message' => $this->errorMessage($response)];
        }

        return ['ok' => true, 'message' => 'ok', 'updates' => $response->json('result', [])];
    }

    /**
     * getUpdates only ever delivers anything if no webhook is registered for this bot - Telegram
     * only uses one delivery method at a time. Idempotent (a no-op if none was set), called
     * before every capture poll rather than once, since there's no reliable moment to call it
     * "just once" on a host with no persistent process to remember that it already ran.
     */
    public function deleteWebhook(): void
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return;
        }

        try {
            Http::timeout(15)->post("https://api.telegram.org/bot{$token}/deleteWebhook");
        } catch (\Throwable) {
            // Best-effort - a failure here just means the next getUpdates call might come back
            // empty, which is already handled as "nothing new" rather than an error.
        }
    }

    /**
     * Downloads a file Telegram is hosting (e.g. a photo from a captured channel post) by its
     * file_id - a two-step process (resolve file_id to a path, then fetch it) that's how the Bot
     * API always serves file content; there's no single "get the bytes" call.
     */
    public function downloadFile(string $fileId): ?string
    {
        $token = $this->settings->getBotToken();

        if (! $token) {
            return null;
        }

        try {
            $fileResponse = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getFile", ['file_id' => $fileId]);
        } catch (\Throwable) {
            return null;
        }

        $filePath = $fileResponse->json('result.file_path');

        if (! $fileResponse->successful() || ! $fileResponse->json('ok') || ! $filePath) {
            return null;
        }

        try {
            $download = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get("https://api.telegram.org/file/bot{$token}/{$filePath}");
        } catch (\Throwable) {
            return null;
        }

        return $download->successful() ? $download->body() : null;
    }

    // Statuses Telegram returns for getChatMember that count as "currently in the channel" - a
    // 'left'/'kicked' member still has a getChatMember row, just not one of these statuses.
    private const CHAT_MEMBER_STATUSES = ['member', 'administrator', 'creator'];

    /**
     * Used by the Giveaway feature to check whether a given Telegram user id is currently a
     * member of the configured channel - independent of the isEnabled() master posting toggle
     * (same reasoning as getUpdates()/deleteWebhook(): checking membership is a different concern
     * from posting content, so it works even while posting is turned off).
     *
     * @return array{ok: bool, message: string, isMember?: bool}
     */
    public function getChatMember(string $userId): array
    {
        $token = $this->settings->getBotToken();
        $chatId = $this->settings->getChannelId();

        if (! $token || ! $chatId) {
            return ['ok' => false, 'message' => 'No bot token or channel id configured - set them up in Telegram Settings first.'];
        }

        try {
            $response = Http::timeout(15)->get("https://api.telegram.org/bot{$token}/getChatMember", [
                'chat_id' => $chatId,
                'user_id' => $userId,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful() || ! $response->json('ok')) {
            return ['ok' => false, 'message' => $this->errorMessage($response)];
        }

        $status = $response->json('result.status');

        return ['ok' => true, 'message' => 'ok', 'isMember' => in_array($status, self::CHAT_MEMBER_STATUSES, true)];
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
     * @return array{ok: bool, message: string, message_id?: int}
     */
    private function parseResponse(\Illuminate\Http\Client\Response $response): array
    {
        if ($response->successful() && $response->json('ok')) {
            $messageId = $response->json('result.message_id');

            return ['ok' => true, 'message' => 'Sent.', ...($messageId !== null ? ['message_id' => $messageId] : [])];
        }

        return ['ok' => false, 'message' => $this->errorMessage($response)];
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('description') ?? $response->body();

        return 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 300, '…');
    }
}
