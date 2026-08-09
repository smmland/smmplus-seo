<?php

namespace App\Services;

use App\Models\TelegramPost;

/**
 * The single place a telegram_posts row actually gets dispatched to the channel and has its
 * status updated afterwards - used by both TelegramSendQueueCommand (the once-a-minute scheduled
 * sweep over due posts) and TelegramQueue's manual "Send now" / "New message" actions, so the
 * post-send bookkeeping (status, sent_at, telegram_message_id, error_message) can never drift
 * between the scheduled path and the manual one.
 */
class TelegramPostSenderService
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly TelegramSettingsService $settings,
    ) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function sendNow(TelegramPost $post): array
    {
        $text = $this->withSignature($post->message_text);

        $result = $post->image_path
            ? $this->bot->sendPhoto($post->image_path, $text)
            : $this->bot->sendMessage($text);

        if ($result['ok']) {
            // telegram_message_id lets TelegramCaptureChannelPostsCommand recognize this exact
            // message when it later polls the channel, so it's never re-captured as if it were
            // someone else's manual post.
            $post->update([
                'status' => TelegramPost::STATUS_SENT,
                'sent_at' => now(),
                'telegram_message_id' => $result['message_id'] ?? null,
                'error_message' => null,
            ]);
        } else {
            $post->update(['status' => TelegramPost::STATUS_FAILED, 'error_message' => $result['message']]);
        }

        return $result;
    }

    // Not stored back onto the post - see TelegramSettingsService::isSignatureEnabled() docblock.
    private function withSignature(string $text): string
    {
        if (! $this->settings->isSignatureEnabled()) {
            return $text;
        }

        $signature = $this->settings->getSignatureText();

        if ($signature === null) {
            return $text;
        }

        return $text."\n\n".$signature;
    }
}
