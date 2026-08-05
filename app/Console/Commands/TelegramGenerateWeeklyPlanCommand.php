<?php

namespace App\Console\Commands;

use App\Services\TelegramPostGeneratorService;
use Illuminate\Console\Command;

/**
 * Runs daily (routes/console.php) - tops up the rolling blog-summary schedule to always have
 * about a week's worth of drafts ahead (TelegramPostGeneratorService::topUpBlogPlan()). Daily
 * rather than a strict once-a-week cron so a missed run just catches up on the next tick instead
 * of leaving the queue empty for days - same self-healing philosophy as every other scheduled
 * command in this app.
 */
class TelegramGenerateWeeklyPlanCommand extends Command
{
    protected $signature = 'telegram:generate-weekly-plan';

    protected $description = 'Tops up the rolling week-ahead schedule of AI-drafted blog-summary Telegram posts';

    public function handle(TelegramPostGeneratorService $generator): int
    {
        $result = $generator->topUpBlogPlan();

        if (($result['message'] ?? null) !== null && $result['created'] === 0) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->info("Created {$result['created']} new blog-summary draft(s).");

        return self::SUCCESS;
    }
}
