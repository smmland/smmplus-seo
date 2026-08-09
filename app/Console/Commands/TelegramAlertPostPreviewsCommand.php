<?php

namespace App\Console\Commands;

use App\Models\TelegramPost;
use App\Services\TelegramAlertService;
use App\Services\TelegramAlertSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs every minute (routes/console.php) - DMs alert recipients a preview (text + image) of any
 * post about to be sent to the channel, a configurable number of minutes ahead of its scheduled_at
 * (default 30, see TelegramAlertSettingsService::getPreviewMinutesBefore()). preview_alert_sent_at
 * is what keeps this from re-alerting on every tick a post sits inside that window - set the
 * moment the alert goes out, checked the same way telegram_message_id already prevents
 * TelegramCaptureChannelPostsCommand from re-capturing a post this panel sent itself.
 */
class TelegramAlertPostPreviewsCommand extends Command
{
    protected $signature = 'telegram:alert-post-previews';

    protected $description = 'DMs alert recipients a preview of posts about to be sent to the channel';

    public function handle(TelegramAlertSettingsService $settings, TelegramAlertService $alerts): int
    {
        if (! $settings->isEnabled() || ! $settings->isOnPostPreviewEnabled() || ! Schema::hasColumn('telegram_posts', 'preview_alert_sent_at')) {
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
            $alerts->notifyPostPreview($post);
            $post->update(['preview_alert_sent_at' => now()]);
        }

        if ($due->isNotEmpty()) {
            $this->info("Sent {$due->count()} pre-send preview alert(s).");
        }

        return self::SUCCESS;
    }
}
