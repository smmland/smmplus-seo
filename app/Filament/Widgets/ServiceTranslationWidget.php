<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\ServiceTranslationQueue;
use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Support\PanelSection;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Schema;

/**
 * The service-translation counterpart to NewBlogTopicsWidget - same shape (a few numbers in a
 * grid, a link to the queue page) for a structurally similar but not identical pipeline: services
 * live on one shared catalog page rather than one URL each, and description/title are tracked and
 * translated fully independently of each other (see ServiceTranslationQueue's own class doc).
 */
class ServiceTranslationWidget extends Widget
{
    protected static string $view = 'filament.widgets.service-translation-widget';

    // Placed after DashboardStatsWidget (sort 3), side by side with TelegramQueueWidget (sort 5) -
    // the Dashboard's own grid is 2 columns (Filament\Pages\Dashboard::getColumns()), so two
    // consecutive column-span-1 widgets sit next to each other instead of each taking a full row.
    protected static ?int $sort = 4;

    protected int | string | array $columnSpan = 1;

    public static function canView(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::TRANSLATION)) ?? false;
    }

    protected function getViewData(): array
    {
        if (! $this->translationTrackingAvailable()) {
            return ['ready' => false, 'queueUrl' => ServiceTranslationQueue::getUrl()];
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        return [
            'ready' => true,
            // Scoped to the default language only - every other language's row for the same
            // service gets its own first_seen_at too (set the moment that language's row is
            // first created), so counting across all languages would multiply each new service
            // by however many active languages exist.
            'newCount' => ServiceTranslation::query()
                ->where('lang', $defaultLang)
                ->where('first_seen_at', '>=', now()->subDay())
                ->count(),
            'needsUploadCount' => $this->needsUploadCount(),
            'inProgressCount' => ServiceTranslationJob::query()
                ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
                ->count(),
            'recentlyRetranslatedCount' => $this->recentlyRetranslatedCount(),
            'queueUrl' => ServiceTranslationQueue::getUrl(),
        ];
    }

    // Mirrors ServiceTranslation::needsSiteUpdate()/titleNeedsSiteUpdate() as queries (a row can
    // count under both if description AND title are each independently waiting to go live) -
    // translated (by AI, or matched live once already) but not yet confirmed live on the actual
    // site.
    private function needsUploadCount(): int
    {
        $descriptionNeedsUpload = ServiceTranslation::query()
            ->where('is_translated', true)
            ->whereNotNull('translated_at')
            ->where(function ($query) {
                $query->whereNull('live_confirmed_at')->orWhereColumn('live_confirmed_at', '<', 'translated_at');
            });

        $titleNeedsUpload = ServiceTranslation::query()
            ->where('is_title_translated', true)
            ->whereNotNull('title_translated_at')
            ->where(function ($query) {
                $query->whereNull('title_live_confirmed_at')->orWhereColumn('title_live_confirmed_at', '<', 'title_translated_at');
            });

        return $descriptionNeedsUpload->count() + $titleNeedsUpload->count();
    }

    // Rows auto-retranslated (source description/title changed since the last translation) within
    // ServiceTranslation::AUTO_RETRANSLATED_MARKER_DAYS - the same window the "Auto re-translated"
    // badge on the queue page itself uses, surfaced here so it's visible without opening that page.
    private function recentlyRetranslatedCount(): int
    {
        $cutoff = now()->subDays(ServiceTranslation::AUTO_RETRANSLATED_MARKER_DAYS);

        return ServiceTranslation::query()
            ->where(function ($query) use ($cutoff) {
                $query->where('description_auto_retranslated_at', '>=', $cutoff)
                    ->orWhere('title_auto_retranslated_at', '>=', $cutoff);
            })
            ->count();
    }

    private static ?bool $translationTrackingAvailable = null;

    private function translationTrackingAvailable(): bool
    {
        return self::$translationTrackingAvailable ??= Schema::hasTable('service_translations')
            && Schema::hasTable('service_translation_jobs')
            && Schema::hasColumn('service_translation_jobs', 'field');
    }
}
