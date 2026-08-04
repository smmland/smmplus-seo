<?php

namespace App\Console\Commands;

use App\Models\BlogTranslationJob;
use App\Models\Language;
use App\Models\Url;
use App\Services\BlogContentExtractionService;
use App\Services\SettingsService;
use App\Services\TranslationSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Gated entirely on Translation Settings' "New blog topics" toggles - a no-op command when both
 * are off, same shape as translation:process-queue skipping while a panel update is installing.
 * When auto-extract is on, works off the backlog of default-language BLOG rows that have never
 * been extracted (content_extracted_at IS NULL) - in steady state that's just whatever the last
 * sitemap sync discovered, but it also naturally catches up on anything still unextracted the
 * first time this is turned on.
 */
class AutoProcessNewBlogsCommand extends Command
{
    protected $signature = 'blog:auto-process-new';

    protected $description = 'Extracts content (and queues AI translation, if enabled) for newly discovered default-language blog topics';

    // Mirrors ProcessBlogTranslationQueueCommand's own budget/cap reasoning: this shares the
    // scheduler with everything else in routes/console.php, so it must not run long enough to
    // fight withoutOverlapping() or the next tick. Extraction downloads every image on a page, so
    // it's slower per-item than a translation job - a smaller budget and a hard per-run cap both
    // keep one unusually large batch of new topics from turning into a runaway invocation.
    private const TIME_BUDGET_SECONDS = 250;

    private const MAX_PER_RUN = 20;

    public function handle(
        TranslationSettingsService $settings,
        BlogContentExtractionService $extractor,
        SettingsService $generalSettings,
    ): int {
        if (! $settings->isAutoExtractNewBlogsEnabled()) {
            return self::SUCCESS;
        }

        if ($generalSettings->isPanelUpdateInProgress()) {
            return self::SUCCESS;
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';
        $autoTranslate = $settings->isAutoTranslateNewBlogsEnabled();
        $translationTrackingAvailable = Schema::hasTable('blog_translation_jobs');

        $rows = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', $defaultLang)
            ->where('is_active', true)
            ->whereNull('content_extracted_at')
            ->oldest('first_seen_at')
            ->limit(self::MAX_PER_RUN)
            ->get();

        $deadline = now()->addSeconds(self::TIME_BUDGET_SECONDS);
        $extracted = 0;
        $failed = 0;
        $queued = 0;

        foreach ($rows as $row) {
            if (now()->gte($deadline)) {
                break;
            }

            $result = $extractor->extract($row);

            if (! $result['ok']) {
                $failed++;

                continue;
            }

            $extracted++;

            if ($autoTranslate && $translationTrackingAvailable) {
                $queued += $this->queueMissingTranslations($row->group_key);
            }
        }

        $this->info("Extracted {$extracted} new blog topic(s), {$failed} failed, queued {$queued} translation(s).");

        return self::SUCCESS;
    }

    /**
     * Same "missing languages for this topic" computation BlogTranslationQueue::
     * translateAllMissingLanguages() uses for its own "queue everything missing" button - a
     * brand-new topic just extracted has no translations yet, so in practice this queues every
     * active non-default language, but reusing the real filter (not "every active language")
     * keeps this safe to also run against an older topic that already has some languages done.
     */
    private function queueMissingTranslations(string $groupKey): int
    {
        $existingByLang = Url::query()->where('group_key', $groupKey)->get()->keyBy('lang');

        $pendingLangs = BlogTranslationJob::query()
            ->where('group_key', $groupKey)
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->pluck('target_lang')
            ->all();

        $missingLanguages = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->get(['code'])
            ->filter(function (Language $language) use ($existingByLang, $pendingLangs) {
                if (in_array($language->code, $pendingLangs, true)) {
                    return false;
                }

                $row = $existingByLang->get($language->code);

                return ! $row || ! $row->looksTranslated();
            });

        foreach ($missingLanguages as $language) {
            BlogTranslationJob::query()->updateOrCreate(
                ['group_key' => $groupKey, 'target_lang' => $language->code],
                ['status' => BlogTranslationJob::QUEUED, 'message' => null],
            );
        }

        return $missingLanguages->count();
    }
}
