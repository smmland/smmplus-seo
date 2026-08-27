<?php

namespace App\Console\Commands;

use App\Models\TelegramPost;
use App\Services\TelegramAutoViewsSettingsService;
use App\Services\TelegramPostViewsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class TelegramTopUpPostViewsCommand extends Command
{
    protected $signature = 'telegram:top-up-post-views';

    protected $description = 'Checks recent public channel posts and orders only the views missing from the configured target';

    public function handle(TelegramAutoViewsSettingsService $settings, TelegramPostViewsService $views): int
    {
        if (! $settings->isEnabled() || ! Schema::hasColumn('telegram_posts', 'views_checked_at')) {
            return self::SUCCESS;
        }

        $posts = TelegramPost::query()
            ->where('status', TelegramPost::STATUS_SENT)
            ->whereNotNull('telegram_message_id')
            ->whereBetween('sent_at', [now()->subDays($settings->getLookbackDays()), now()->subMinutes(3)])
            ->where(function ($query) {
                $query->whereNull('views_checked_at')
                    ->orWhere('views_checked_at', '<=', now()->subMinutes(15));
            })
            // Unchecked posts sort first; afterwards the least-recently checked posts win. This
            // avoids a busy channel's newest rows permanently starving older rows in the window.
            ->orderBy('views_checked_at')
            ->orderByDesc('sent_at')
            ->limit($settings->getMaxPostsPerRun())
            ->get();

        $totals = ['ordered' => 0, 'healthy' => 0, 'cooldown' => 0, 'failed' => 0];

        foreach ($posts as $post) {
            $result = $views->topUpViewsFor($post);

            if (array_key_exists($result, $totals)) {
                $totals[$result]++;
            }
        }

        if ($posts->isNotEmpty()) {
            $this->info("Checked {$posts->count()} post(s): {$totals['ordered']} ordered, {$totals['healthy']} already at target, {$totals['cooldown']} awaiting delivery, {$totals['failed']} failed.");
        }

        return self::SUCCESS;
    }
}
