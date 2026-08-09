<?php

namespace App\Services;

use App\Models\TelegramAlertRecipient;
use App\Models\TelegramPost;
use Illuminate\Support\Facades\Schema;

/**
 * Sends personal DM alerts to every linked TelegramAlertRecipient - entirely separate from the
 * channel-posting flow (TelegramPostSenderService). Each notify*() method checks its own event
 * toggle (TelegramAlertSettingsService) before doing anything, so callers don't need to guard
 * their own call sites - it's always safe to call these unconditionally from a hook point.
 */
class TelegramAlertService
{
    public function __construct(
        private readonly TelegramBotService $bot,
        private readonly TelegramAlertSettingsService $settings,
    ) {}

    public function notifyNewService(string $title, ?string $categoryTitle): void
    {
        if (! $this->settings->isOnNewServiceEnabled()) {
            return;
        }

        $category = $categoryTitle ? " ({$categoryTitle})" : '';
        $this->broadcast("🆕 New service added:\n{$title}{$category}");
    }

    public function notifyServiceChanged(string $title, ?string $categoryTitle): void
    {
        if (! $this->settings->isOnServiceChangedEnabled()) {
            return;
        }

        $category = $categoryTitle ? " ({$categoryTitle})" : '';
        $this->broadcast("♻️ Service changed - needs translation:\n{$title}{$category}");
    }

    public function notifyNewTranslatableText(string $title): void
    {
        if (! $this->settings->isOnNewTextEnabled()) {
            return;
        }

        $this->broadcast("📝 New content added - needs translation:\n{$title}");
    }

    public function notifyPostPreview(TelegramPost $post): void
    {
        if (! $this->settings->isOnPostPreviewEnabled()) {
            return;
        }

        $minutes = $post->scheduled_at->diffInMinutes(now(), true);
        $caption = "⏰ Sending to the channel in about {$minutes} minute(s):\n\n{$post->message_text}";

        $this->broadcast($caption, $post->image_path);
    }

    private function broadcast(string $text, ?string $imagePath = null): void
    {
        if (! $this->settings->isEnabled() || ! Schema::hasTable('telegram_alert_recipients')) {
            return;
        }

        $recipients = TelegramAlertRecipient::query()->whereNotNull('chat_id')->get();

        foreach ($recipients as $recipient) {
            if ($imagePath) {
                $this->bot->sendPhotoTo($recipient->chat_id, $imagePath, $text);
            } else {
                $this->bot->sendMessageTo($recipient->chat_id, $text);
            }
        }
    }
}
