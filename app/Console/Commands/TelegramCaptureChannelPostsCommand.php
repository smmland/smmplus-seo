<?php

namespace App\Console\Commands;

use App\Models\TelegramPost;
use App\Services\TelegramBotService;
use App\Services\TelegramSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs every minute (routes/console.php), gated on TelegramSettingsService::
 * isChannelCaptureEnabled() - a toggle independent of the master "post to Telegram" one, since
 * watching the channel is a different concern from writing to it. Polls Telegram's getUpdates for
 * channel_post updates and records anything that isn't already a post this panel sent itself
 * (telegram_message_id already on file) as a new TYPE_MANUAL row - someone posted directly to
 * the channel outside this tool. This can only ever see posts made *after* capture is turned on
 * and the bot is an admin on the channel - Telegram's Bot API has no way to fetch a channel's
 * older history, that requires a full user account session (MTProto), not a bot token.
 */
class TelegramCaptureChannelPostsCommand extends Command
{
    protected $signature = 'telegram:capture-channel-posts';

    protected $description = 'Records channel posts sent outside this panel by polling Telegram for updates';

    // Safety cap on how many getUpdates pages one run will drain - Telegram returns at most 100
    // updates per call, so this is generous headroom for a channel that's realistically never
    // going to have thousands of posts land between two once-a-minute ticks.
    private const MAX_PAGES = 20;

    public function handle(TelegramSettingsService $settings, TelegramBotService $bot): int
    {
        if (! $settings->isChannelCaptureEnabled() || ! $settings->hasBotToken()) {
            return self::SUCCESS;
        }

        $bot->deleteWebhook();

        $channelId = $settings->getChannelId();
        $offset = ($settings->getLastUpdateId() ?? -1) + 1;
        $captured = 0;

        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $result = $bot->getUpdates($offset);

            if (! $result['ok']) {
                $this->error($result['message']);
                break;
            }

            $updates = $result['updates'] ?? [];

            if (empty($updates)) {
                break;
            }

            foreach ($updates as $update) {
                $offset = max($offset, $update['update_id'] + 1);

                $post = $update['channel_post'] ?? null;

                if (! $post || ! $this->belongsToConfiguredChannel($post['chat'] ?? [], $channelId)) {
                    continue;
                }

                $messageId = $post['message_id'] ?? null;

                if ($messageId === null) {
                    continue;
                }

                // Already ours - sent through this panel and tracked via
                // TelegramSendQueueCommand, not an external post.
                if (TelegramPost::query()->where('telegram_message_id', $messageId)->exists()) {
                    continue;
                }

                $this->captureOne($post, $messageId, $bot);
                $captured++;
            }

            $settings->setLastUpdateId($offset - 1);

            if (count($updates) < 100) {
                break;
            }
        }

        if ($captured > 0) {
            $this->info("Captured {$captured} manually-posted channel message(s).");
        }

        return self::SUCCESS;
    }

    /**
     * The configured channel id may be an @username or a numeric -100... chat id - matched
     * against whichever of those the update actually carries. If neither can be compared (a bare
     * username configured but the update's chat has none, or nothing configured at all), this
     * falls back to accepting the post rather than silently dropping it - the bot realistically
     * is only ever added to the one channel this panel manages, so an unmatched-but-unmatchable
     * post is still almost certainly from it.
     */
    private function belongsToConfiguredChannel(array $chat, ?string $channelId): bool
    {
        if (! $channelId) {
            return true;
        }

        if (str_starts_with($channelId, '@')) {
            $username = $chat['username'] ?? null;

            return $username === null || strcasecmp(ltrim($channelId, '@'), $username) === 0;
        }

        $chatId = $chat['id'] ?? null;

        return $chatId === null || (string) $chatId === (string) $channelId;
    }

    private function captureOne(array $post, int $messageId, TelegramBotService $bot): void
    {
        $text = $post['text'] ?? $post['caption'] ?? '';
        $sentAt = isset($post['date']) ? \Illuminate\Support\Carbon::createFromTimestamp($post['date']) : now();

        [$imagePath, $imageSource] = $this->resolveCapturedImage($post, $bot);

        TelegramPost::create([
            'type' => TelegramPost::TYPE_MANUAL,
            'lang' => 'unknown',
            'related_key' => null,
            'title' => $text !== '' ? Str::limit($text, 60) : 'Manually posted message',
            'message_text' => $text,
            'image_path' => $imagePath,
            'image_source' => $imageSource,
            'scheduled_at' => $sentAt,
            'status' => TelegramPost::STATUS_SENT,
            'sent_at' => $sentAt,
            'telegram_message_id' => $messageId,
        ]);
    }

    /**
     * @return array{0: ?string, 1: string} [diskPath, imageSource]
     */
    private function resolveCapturedImage(array $post, TelegramBotService $bot): array
    {
        $photos = $post['photo'] ?? null;

        if (! $photos) {
            return [null, TelegramPost::IMAGE_NONE];
        }

        // Telegram lists photo sizes smallest-first - the last entry is the highest resolution
        // available.
        $fileId = end($photos)['file_id'] ?? null;

        if (! $fileId) {
            return [null, TelegramPost::IMAGE_NONE];
        }

        $bytes = $bot->downloadFile($fileId);

        if (! $bytes) {
            return [null, TelegramPost::IMAGE_NONE];
        }

        $path = 'telegram/images/'.Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put($path, $bytes);

        return [$path, TelegramPost::IMAGE_CAPTURED];
    }
}
