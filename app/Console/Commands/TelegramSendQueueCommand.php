<?php

namespace App\Console\Commands;

use App\Models\TelegramPost;
use App\Services\TelegramPostSenderService;
use App\Services\TelegramSettingsService;
use Illuminate\Console\Command;

/**
 * Runs every minute (routes/console.php) - sends every pending/confirmed post whose
 * scheduled_at has passed. "Confirmed" isn't a prerequisite to send, only "rejected" stops one -
 * per the product decision that an unreviewed post still goes out at its scheduled time rather
 * than silently never sending, with reject as the only way to cancel one.
 */
class TelegramSendQueueCommand extends Command
{
    protected $signature = 'telegram:send-queue';

    protected $description = 'Sends every due, non-rejected Telegram post';

    public function handle(TelegramSettingsService $settings, TelegramPostSenderService $sender): int
    {
        if (! $settings->isEnabled()) {
            return self::SUCCESS;
        }

        $due = TelegramPost::query()
            ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->get();

        $sent = 0;
        $failed = 0;

        foreach ($due as $post) {
            $sender->sendNow($post)['ok'] ? $sent++ : $failed++;
        }

        if ($sent > 0 || $failed > 0) {
            $this->info("Sent {$sent} post(s), {$failed} failed.");
        }

        return self::SUCCESS;
    }
}
