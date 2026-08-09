<?php

namespace App\Console\Commands;

use App\Models\TelegramAlertRecipient;
use App\Models\TelegramPost;
use App\Services\TelegramBotService;
use App\Services\TelegramSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Runs every minute (routes/console.php). Two independent jobs share this one poll because they
 * share Telegram's one getUpdates offset cursor for this bot (see TelegramBotService::
 * getUpdates()'s docblock) - splitting them into separate commands each polling on their own would
 * risk one silently losing updates the other hasn't processed yet:
 *
 * 1. Gated on TelegramSettingsService::isChannelCaptureEnabled() - records anything posted
 *    directly to the channel outside this panel (telegram_message_id not already on file) as a
 *    new TYPE_MANUAL row. Can only ever see posts made *after* capture is turned on and the bot is
 *    an admin on the channel - Telegram's Bot API has no way to fetch a channel's older history.
 * 2. Always active whenever there's a pending (unlinked) TelegramAlertRecipient - watches for a
 *    private "/start <token>" message and fills in that recipient's chat_id once its deep link is
 *    opened (Telegram Channel > Alerts).
 */
class TelegramCaptureChannelPostsCommand extends Command
{
    protected $signature = 'telegram:capture-channel-posts';

    protected $description = 'Records channel posts sent outside this panel and links pending alert recipients, by polling Telegram for updates';

    // Safety cap on how many getUpdates pages one run will drain - Telegram returns at most 100
    // updates per call, so this is generous headroom for a channel that's realistically never
    // going to have thousands of posts land between two once-a-minute ticks.
    private const MAX_PAGES = 20;

    public function handle(TelegramSettingsService $settings, TelegramBotService $bot): int
    {
        $captureEnabled = $settings->isChannelCaptureEnabled();
        $recipientsTableReady = Schema::hasTable('telegram_alert_recipients');
        $hasPendingLinks = $recipientsTableReady && TelegramAlertRecipient::query()->whereNull('chat_id')->exists();

        if ((! $captureEnabled && ! $hasPendingLinks) || ! $settings->hasBotToken()) {
            return self::SUCCESS;
        }

        $bot->deleteWebhook();

        $channelId = $settings->getChannelId();
        $offset = ($settings->getLastUpdateId() ?? -1) + 1;
        $captured = 0;
        $linked = 0;

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

                if ($captureEnabled && $this->captureChannelPost($update['channel_post'] ?? null, $channelId, $bot)) {
                    $captured++;
                }

                if ($hasPendingLinks && $this->tryLinkRecipient($update['message'] ?? null, $bot)) {
                    $linked++;
                }
            }

            $settings->setLastUpdateId($offset - 1);

            if (count($updates) < 100) {
                break;
            }
        }

        if ($captured > 0) {
            $this->info("Captured {$captured} manually-posted channel message(s).");
        }

        if ($linked > 0) {
            $this->info("Linked {$linked} alert recipient(s).");
        }

        return self::SUCCESS;
    }

    private function captureChannelPost(?array $post, ?string $channelId, TelegramBotService $bot): bool
    {
        if (! $post || ! $this->belongsToConfiguredChannel($post['chat'] ?? [], $channelId)) {
            return false;
        }

        $messageId = $post['message_id'] ?? null;

        if ($messageId === null) {
            return false;
        }

        // Already ours - sent through this panel and tracked via TelegramSendQueueCommand, not an
        // external post.
        if (TelegramPost::query()->where('telegram_message_id', $messageId)->exists()) {
            return false;
        }

        $this->captureOne($post, $messageId, $bot);

        return true;
    }

    /**
     * Matches a private "/start <token>" message against a pending TelegramAlertRecipient's
     * link_token (the deep link shown on Telegram Channel > Alerts is t.me/<bot>?start=<token>,
     * which Telegram expands into exactly this message the moment the recipient opens it) and
     * fills in its chat_id - from that point on, TelegramAlertService can DM this recipient.
     */
    private function tryLinkRecipient(?array $message, TelegramBotService $bot): bool
    {
        if (! $message || ($message['chat']['type'] ?? null) !== 'private') {
            return false;
        }

        $text = trim((string) ($message['text'] ?? ''));

        if (! str_starts_with($text, '/start ')) {
            return false;
        }

        $token = trim(substr($text, 7));

        if ($token === '') {
            return false;
        }

        $recipient = TelegramAlertRecipient::query()->where('link_token', $token)->whereNull('chat_id')->first();

        if (! $recipient) {
            return false;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');

        $recipient->update([
            'chat_id' => $chatId,
            'telegram_username' => $message['chat']['username'] ?? $message['from']['username'] ?? null,
            'linked_at' => now(),
        ]);

        $bot->sendMessageTo($chatId, "✅ Connected! You'll now receive alerts from the SMM Plus panel here.");

        return true;
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
