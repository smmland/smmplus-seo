<?php

namespace App\Console\Commands;

use App\Models\BlogTranslationJob;
use App\Services\AiSettingsService;
use App\Services\BlogAiTranslationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces a bare `artisan queue:work` as the scheduled consumer of blog_translation_jobs - this
 * host has no persistent worker process, so both approaches ride the same once-a-minute cron
 * tick, but queue:work only ever processes one job at a time. This pulls up to the admin's
 * configured concurrency (AI Settings: Max concurrent translations, default 3) at once and fires
 * them at the provider together via BlogAiTranslationService::translateManyConcurrently().
 */
class ProcessBlogTranslationQueueCommand extends Command
{
    protected $signature = 'translation:process-queue';

    protected $description = 'Processes queued blog translation jobs, up to the configured number concurrently';

    // Mirrors the ceiling the old queue:work invocation used (--timeout=900) - long enough to
    // absorb several slow AI calls in sequence across batches, short enough that
    // withoutOverlapping() in routes/console.php never has to fight a runaway invocation.
    private const TIME_BUDGET_SECONDS = 850;

    public function handle(AiSettingsService $aiSettings, BlogAiTranslationService $translator): int
    {
        if (! Schema::hasTable('blog_translation_jobs')) {
            return self::SUCCESS;
        }

        $concurrency = $aiSettings->getMaxConcurrentTranslations();
        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $processed = 0;

        while (now()->lt($deadline)) {
            $batch = BlogTranslationJob::query()
                ->where('status', BlogTranslationJob::QUEUED)
                ->oldest()
                ->limit($concurrency)
                ->get();

            if ($batch->isEmpty()) {
                break;
            }

            BlogTranslationJob::query()->whereIn('id', $batch->pluck('id'))->update(['status' => BlogTranslationJob::RUNNING]);

            $results = $translator->translateManyConcurrently($batch);

            foreach ($batch as $job) {
                $result = $results[$job->id] ?? ['ok' => false, 'message' => 'No result returned.'];

                $job->update([
                    'status' => $result['ok'] ? BlogTranslationJob::DONE : BlogTranslationJob::FAILED,
                    'message' => $result['message'],
                ]);

                $processed++;
            }
        }

        $this->info("Processed {$processed} translation job(s) at concurrency {$concurrency}.");

        return self::SUCCESS;
    }
}
