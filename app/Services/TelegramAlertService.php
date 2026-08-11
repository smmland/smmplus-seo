<?php

namespace App\Services;

use App\Models\TelegramAlertRecipient;
use App\Models\TelegramPost;
use Illuminate\Support\Facades\Log;
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

    public function notifyTranslationCompleted(string $message): void
    {
        if (! $this->settings->isOnTranslationCompletedEnabled()) {
            return;
        }

        $this->broadcast("✅ {$message}");
    }

    public function notifyAttackDetected(int $blockedCount): void
    {
        if (! $this->settings->isOnAttackDetectedEnabled()) {
            return;
        }

        $this->broadcast("🚨 Possible attack detected on the Free Service Gateway:\n{$blockedCount}+ requests blocked in the last minute. The gateway's own defenses (auto-block, Tor blocking, rate limiting) are actively rejecting them.");
    }

    public function notifyAttackSubsided(int $minutes, int $blockedIpsDuringIncident): void
    {
        if (! $this->settings->isOnAttackDetectedEnabled()) {
            return;
        }

        $ips = $blockedIpsDuringIncident === 1 ? '1 IP was' : "{$blockedIpsDuringIncident} IPs were";
        $this->broadcast("✅ The gateway attack appears to have subsided after {$minutes} minute(s).\n{$ips} auto-blocked during the incident.");
    }

    public function notifyPostPreview(TelegramPost $post): void
    {
        if (! $this->settings->isOnPostPreviewEnabled()) {
            return;
        }

        $minutes = round($post->scheduled_at->diffInMinutes(now(), true));
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
            $result = $imagePath
                ? $this->bot->sendPhotoTo($recipient->chat_id, $imagePath, $text)
                : $this->bot->sendMessageTo($recipient->chat_id, $text);

            // The previous version discarded this result entirely - a failed DM (bot blocked,
            // chat not found, bad token, ...) left no trace anywhere, which is indistinguishable
            // from "nothing to alert about" from the recipient's side.
            if (! ($result['ok'] ?? false)) {
                Log::warning('Telegram alert failed to send', [
                    'recipient_id' => $recipient->id,
                    'recipient_label' => $recipient->label,
                    'error' => $result['message'] ?? 'unknown error',
                ]);
            }
        }
    }
}
