<?php

namespace App\Console\Commands;

use App\Filament\Pages\CategoryTranslationQueue;
use App\Models\CategoryTranslationJob;
use App\Services\AiSettingsService;
use App\Services\CategoryAiTranslationService;
use App\Services\PanelNotificationService;
use App\Services\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The category counterpart to ProcessServiceTranslationQueueCommand - drains
 * category_translation_jobs up to the same configured concurrency (AI Settings: Max concurrent
 * translations), same shared setting every translation queue in this app uses since they all
 * fire at the same provider/API key.
 */
class ProcessCategoryTranslationQueueCommand extends Command
{
    protected $signature = 'categories:process-queue';

    protected $description = 'Processes queued category-name translation jobs, up to the configured number concurrently';

    // A category name is far shorter than even a service title - comfortably under one cron tick.
    private const TIME_BUDGET_SECONDS = 120;

    public function handle(AiSettingsService $aiSettings, CategoryAiTranslationService $translator, PanelNotificationService $notifications, TelegramAlertService $alerts): int
    {
        if (! Schema::hasTable('category_translation_jobs')) {
            return self::SUCCESS;
        }

        $concurrency = $aiSettings->getMaxConcurrentTranslations();
        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $processed = 0;
        $doneCount = 0;

        while (now()->lt($deadline)) {
            $batch = CategoryTranslationJob::query()
                ->where('status', CategoryTranslationJob::QUEUED)
                ->oldest()
                ->limit($concurrency)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            CategoryTranslationJob::query()->whereIn('id', $batch->pluck('id'))->update(['status' => CategoryTranslationJob::RUNNING]);

            $results = $translator->translateManyConcurrently($batch);

            foreach ($batch as $job) {
                $result = $results[$job->id] ?? ['ok' => false, 'message' => 'No result returned.'];

                // Conditional on still being RUNNING - an admin may have cancelled this exact job
                // while the AI call was in flight, and that must stick rather than being
                // clobbered back to done/failed once the response comes back.
                $wasApplied = CategoryTranslationJob::query()
                    ->where('id', $job->id)
                    ->where('status', CategoryTranslationJob::RUNNING)
                    ->update([
                        'status' => $result['ok'] ? CategoryTranslationJob::DONE : CategoryTranslationJob::FAILED,
                        'message' => $result['message'],
                        'provider' => $result['provider'] ?? null,
                        'model' => $result['model'] ?? null,
                        'input_tokens' => $result['input_tokens'] ?? null,
                        'output_tokens' => $result['output_tokens'] ?? null,
                        'estimated_cost_usd' => $result['estimated_cost_usd'] ?? null,
                    ]);

                $processed++;

                if ($wasApplied && $result['ok']) {
                    $doneCount++;
                }
            }
        }

        if ($doneCount > 0) {
            $notifications->notify('translation', 'translation_completed', "{$doneCount} category translation(s) completed", null, CategoryTranslationQueue::getUrl());
            $alerts->notifyTranslationCompleted("{$doneCount} category translation(s) completed");
        }

        $this->info("Processed {$processed} category translation job(s) at concurrency {$concurrency}.");

        return self::SUCCESS;
    }
}
