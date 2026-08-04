<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\BlogTranslationQueue;
use App\Models\BlogTranslationJob;
use App\Models\Language;
use App\Models\Url;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Schema;

/**
 * A dedicated card for the blog-translation pipeline, split into three numbers instead of
 * cramming them into one StatsOverviewWidget description string (which just wrapped into an
 * unreadable multi-line paragraph - see DashboardStatsWidget's history for the version this
 * replaced): how many topics the sitemap sync found today, how many existing translations still
 * need to be confirmed live on the actual site, and how many are mid-flight at the AI provider
 * right now.
 */
class NewBlogTopicsWidget extends Widget
{
    protected static string $view = 'filament.widgets.new-blog-topics-widget';

    protected static ?int $sort = 2;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $newCount = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', $defaultLang)
            ->where('first_seen_at', '>=', now()->subDay())
            ->count();

        return [
            'newCount' => $newCount,
            'notUploadedCount' => $this->notUploadedTranslationsCount($defaultLang),
            'inProgressCount' => $this->inProgressTranslationsCount(),
            'queueUrl' => BlogTranslationQueue::getUrl(),
        ];
    }

    // Mirrors Url::needsSiteUpdate() as a query (gated on looksTranslated() first, same as
    // HiddenTranslationService's own looksTranslatedClause()) - a translation this tool has
    // already produced (AI translation, or a live check that confirmed one) but that hasn't since
    // been confirmed live on the actual site, whether because it's simply never been checked yet
    // or because the content was re-extracted after the last check. site_update_override lets an
    // admin force this regardless of what the automatic checks think.
    private function notUploadedTranslationsCount(string $defaultLang): int
    {
        return Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', '!=', $defaultLang)
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('is_translated', true)
                    ->orWhere(function ($query) {
                        $query->whereNull('is_translated')->whereNotNull('content_extraction_path');
                    });
            })
            ->where(function ($query) {
                $query->where('site_update_override', true)
                    ->orWhereNull('translation_checked_at')
                    ->orWhereNull('translation_title')
                    ->orWhereColumn('content_extracted_at', '>', 'translation_checked_at');
            })
            ->count();
    }

    // Queued or actively running blog_translation_jobs rows - see
    // ProcessBlogTranslationQueueCommand, the scheduled command that drains these.
    private function inProgressTranslationsCount(): int
    {
        if (! Schema::hasTable('blog_translation_jobs')) {
            return 0;
        }

        return BlogTranslationJob::query()
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->count();
    }
}
