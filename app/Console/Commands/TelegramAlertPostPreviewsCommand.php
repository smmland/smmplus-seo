<?php

namespace App\Console\Commands;

use App\Filament\Pages\TelegramQueue;
use App\Models\TelegramPost;
use App\Services\PanelNotificationService;
use App\Services\TelegramAlertService;
use App\Services\TelegramAlertSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs every minute (routes/console.php) - previews (text + image) any post about to be sent to
 * the channel, a configurable number of minutes ahead of its scheduled_at (default 30, see
 * TelegramAlertSettingsService::getPreviewMinutesBefore()), on two independent channels: a
 * personal DM (gated by the Telegram-specific alert toggles, same as every other TelegramAlertService
 * event) and an in-panel notification (gated only by the viewing admin's own Telegram-section
 * access, same as every other PanelNotificationService event - never conditioned on whether DM
 * alerts happen to be configured). preview_alert_sent_at is what keeps this from re-alerting on
 * every tick a post sits inside that window - set the moment either channel is processed for it,
 * checked the same way telegram_message_id already prevents TelegramCaptureChannelPostsCommand
 * from re-capturing a post this panel sent itself.
 */
class TelegramAlertPostPreviewsCommand extends Command
{
    protected $signature = 'telegram:alert-post-previews';

    protected $description = 'Previews posts about to be sent to the channel, via DM and in-panel notification';

    public function handle(TelegramAlertSettingsService $settings, TelegramAlertService $alerts, PanelNotificationService $notifications): int
    {
        if (! Schema::hasColumn('telegram_posts', 'preview_alert_sent_at')) {
            return self::SUCCESS;
        }

        $windowEnd = now()->addMinutes($settings->getPreviewMinutesBefore());

        $due = TelegramPost::query()
            ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
            ->whereNull('preview_alert_sent_at')
            ->where('scheduled_at', '>', now())
            ->where('scheduled_at', '<=', $windowEnd)
            ->orderBy('scheduled_at')
            ->get();

        foreach ($due as $post) {
            if ($settings->isEnabled() && $settings->isOnPostPreviewEnabled()) {
                $alerts->notifyPostPreview($post);
            }

            $minutes = round($post->scheduled_at->diffInMinutes(now(), true));
            $notifications->notify('telegram', 'post_preview', "Sending to the channel in about {$minutes} minute(s): {$post->title}", null, TelegramQueue::getUrl());

            $post->update(['preview_alert_sent_at' => now()]);
        }

        if ($due->isNotEmpty()) {
            $this->info("Processed {$due->count()} pre-send preview(s).");
        }

        return self::SUCCESS;
    }
}
