<?php

namespace App\Console\Commands;

use App\Filament\Pages\ServiceTranslationQueue;
use App\Models\ServiceTranslationJob;
use App\Services\AiSettingsService;
use App\Services\PanelNotificationService;
use App\Services\ServiceAiTranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The services counterpart to ProcessBlogTranslationQueueCommand - drains service_translation_jobs
 * up to the configured concurrency (AI Settings: Max concurrent translations), same shared setting
 * both queues use since they fire at the same provider/API key.
 */
class ProcessServiceTranslationQueueCommand extends Command
{
    protected $signature = 'services:process-queue';

    protected $description = 'Processes queued service-description translation jobs, up to the configured number concurrently';

    // A service description is far shorter than a blog article, so this needs nowhere near
    // translation:process-queue's 850s budget - kept comfortably under the same cron tick.
    private const TIME_BUDGET_SECONDS = 240;

    public function handle(AiSettingsService $aiSettings, ServiceAiTranslationService $translator, PanelNotificationService $notifications): int
    {
        if (! Schema::hasTable('service_translation_jobs')) {
            return self::SUCCESS;
        }

        $concurrency = $aiSettings->getMaxConcurrentTranslations();
        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $processed = 0;
        $doneCount = 0;

        while (now()->lt($deadline)) {
            $batch = ServiceTranslationJob::query()
                ->where('status', ServiceTranslationJob::QUEUED)
                ->oldest()
                ->limit($concurrency)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            ServiceTranslationJob::query()->whereIn('id', $batch->pluck('id'))->update(['status' => ServiceTranslationJob::RUNNING]);

            $results = $translator->translateManyConcurrently($batch);

            foreach ($batch as $job) {
                $result = $results[$job->id] ?? ['ok' => false, 'message' => 'No result returned.'];

                // Conditional on still being RUNNING - an admin may have cancelled this exact job
                // while the AI call was in flight, and that must stick rather than being
                // clobbered back to done/failed once the response comes back.
                $wasApplied = ServiceTranslationJob::query()
                    ->where('id', $job->id)
                    ->where('status', ServiceTranslationJob::RUNNING)
                    ->update([
                        'status' => $result['ok'] ? ServiceTranslationJob::DONE : ServiceTranslationJob::FAILED,
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

        // One batched summary per run rather than one per job - this can plausibly finish dozens
        // of jobs in a single tick, and a notification per job would flood the panel.
        if ($doneCount > 0) {
            $notifications->notify('translation', 'translation_completed', "{$doneCount} service translation(s) completed", null, ServiceTranslationQueue::getUrl());
        }

        $this->info("Processed {$processed} service translation job(s) at concurrency {$concurrency}.");

        return self::SUCCESS;
    }
}
