<?php

namespace App\Console\Commands;

use App\Filament\Pages\ServiceTranslationQueue;
use App\Filament\Pages\TelegramQueue;
use App\Models\CategoryTranslation;
use App\Models\CategoryTranslationJob;
use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Services\PanelNotificationService;
use App\Services\ServiceCatalogService;
use App\Services\TelegramAlertService;
use App\Services\TelegramPostGeneratorService;
use App\Services\TranslationSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs hourly (routes/console.php) - re-syncs the default-language services catalog (picking up
 * new/changed services), re-checks every active language's live page for what's already
 * genuinely translated, then auto-queues AI translation for whatever's still missing - including
 * anything that was already translated but whose default-language description/title has since
 * changed (ServiceCatalogService::syncDefaultCatalog() resets is_translated/is_title_translated
 * to null for those, which reads as "missing" to queueMissing() below, just tagged with a
 * different trigger so the eventual translation gets marked as an automatic re-translation
 * rather than a first-time one). Unlike blog topics, there's no separate "extract content" step
 * to gate this behind: a service's description is short plain text pulled directly off the
 * shared listing page, not a whole article that needs image downloads and style conversion first.
 */
class RefreshServiceCatalogCommand extends Command
{
    protected $signature = 'services:refresh-catalog';

    protected $description = 'Re-syncs the default-language services catalog, checks translation status per language, and queues missing/changed AI translations';

    public function handle(
        ServiceCatalogService $catalog,
        TranslationSettingsService $settings,
        TelegramPostGeneratorService $telegramPosts,
        TelegramAlertService $alerts,
        PanelNotificationService $notifications,
    ): int {
        $sync = $catalog->syncDefaultCatalog();

        if (! $sync['ok']) {
            $this->error('Could not sync the default-language catalog: '.$sync['error']);

            return self::FAILURE;
        }

        // Drafts one Telegram post per added/updated/removed service (no-op if Telegram
        // integration is disabled) - reuses this sync's own detection rather than a second fetch.
        $telegramPosts->draftServiceChanges(
            $sync['addedServices'] ?? [],
            $sync['changedServices'] ?? [],
            $sync['removedServices'] ?? [],
        );

        // Personal DM alerts (no-op if alerts are disabled or nobody's linked) - same detection,
        // reused a third time. In-panel notifications are independent of the Telegram-specific
        // toggles - visibility there is gated purely by the viewing admin's own section access
        // (NotificationBell), not by whether DM alerts happen to be configured.
        foreach ($sync['addedServices'] ?? [] as $service) {
            $title = $service['title'] ?? $service['service_key'];
            $alerts->notifyNewService($title, $service['category_title'] ?? null);
            $notifications->notify('telegram', 'new_service', "New service added: {$title}", $service['category_title'] ?? null, TelegramQueue::getUrl());
        }

        foreach ($sync['changedServices'] ?? [] as $service) {
            $title = $service['title'] ?? $service['service_key'];
            $alerts->notifyServiceChanged($title, $service['category_title'] ?? null);
            $notifications->notify('translation', 'service_changed', "Service changed - needs translation: {$title}", $service['category_title'] ?? null, ServiceTranslationQueue::getUrl());
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';
        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->pluck('code');

        $totalChecked = 0;
        $totalTranslated = 0;

        foreach ($activeLanguages as $langCode) {
            $result = $catalog->refreshLanguage($langCode);

            if ($result['ok']) {
                $totalChecked += $result['checked'];
                $totalTranslated += $result['translated'];
            }
        }

        $queued = 0;
        $retranslated = 0;

        if (Schema::hasTable('service_translation_jobs') && Schema::hasColumn('service_translation_jobs', 'trigger')) {
            foreach ([ServiceTranslationJob::FIELD_DESCRIPTION, ServiceTranslationJob::FIELD_TITLE] as $field) {
                $result = $this->queueMissing($defaultLang, $activeLanguages, $field);
                $queued += $result['queued'];
                $retranslated += $result['retranslated'];
            }
        }

        $categoriesQueued = 0;

        if (Schema::hasTable('category_translation_jobs')) {
            $categoriesQueued = $this->queueMissingCategories($defaultLang, $activeLanguages)['queued'];
        }

        $settings->recordServiceScheduledRun();

        $this->info("Synced {$sync['total']} service(s) ({$sync['new']} new, {$sync['changed']} changed). Checked {$totalChecked}, translated {$totalTranslated}, queued {$queued} ({$retranslated} re-translations due to a content change), {$categoriesQueued} category translation(s) queued.");

        return self::SUCCESS;
    }

    /**
     * The category counterpart to queueMissing() above - same "missing = no row yet, or exists
     * but not looksTranslated(), and not already queued" shape, just against
     * CategoryTranslation/CategoryTranslationJob (one field - a category has only a name, no
     * separate description) instead of per-field service rows.
     *
     * @return array{queued: int, retranslated: int}
     */
    private function queueMissingCategories(string $defaultLang, \Illuminate\Support\Collection $activeLanguages): array
    {
        $categoryIds = CategoryTranslation::query()->where('lang', $defaultLang)->pluck('category_id');

        if ($categoryIds->isEmpty() || $activeLanguages->isEmpty()) {
            return ['queued' => 0, 'retranslated' => 0];
        }

        $existing = CategoryTranslation::query()
            ->whereIn('category_id', $categoryIds)
            ->whereIn('lang', $activeLanguages)
            ->get()
            ->keyBy(fn (CategoryTranslation $row) => $row->category_id.'|'.$row->lang);

        $pending = CategoryTranslationJob::query()
            ->whereIn('category_id', $categoryIds)
            ->whereIn('status', CategoryTranslationJob::PENDING_STATUSES)
            ->get()
            ->keyBy(fn (CategoryTranslationJob $job) => $job->category_id.'|'.$job->target_lang);

        $queued = 0;
        $retranslated = 0;

        foreach ($categoryIds as $categoryId) {
            foreach ($activeLanguages as $langCode) {
                $key = $categoryId.'|'.$langCode;

                if ($pending->has($key)) {
                    continue;
                }

                $row = $existing->get($key);

                if ($row && $row->looksTranslated()) {
                    continue;
                }

                $wasTranslatedBefore = $row && $row->translated_at !== null;
                $trigger = $wasTranslatedBefore ? CategoryTranslationJob::TRIGGER_SOURCE_CHANGED : CategoryTranslationJob::TRIGGER_MISSING;

                CategoryTranslationJob::query()->updateOrCreate(
                    ['category_id' => $categoryId, 'target_lang' => $langCode],
                    ['status' => CategoryTranslationJob::QUEUED, 'message' => null, 'trigger' => $trigger],
                );

                $queued++;

                if ($trigger === CategoryTranslationJob::TRIGGER_SOURCE_CHANGED) {
                    $retranslated++;
                }
            }
        }

        return ['queued' => $queued, 'retranslated' => $retranslated];
    }

    /**
     * Same "missing = no row yet, or exists but not looksTranslated(), and not already queued"
     * shape AutoProcessNewBlogsCommand uses for blog topics - queues every (service, language)
     * pair that's neither confirmed translated nor already mid-flight, for one field
     * (description or title) at a time. Description and title are queued as fully independent
     * jobs (see the service_translation_jobs.field column) - a service missing both gets two
     * separate jobs, and each is retried/tracked on its own.
     *
     * @return array{queued: int, retranslated: int}
     */
    private function queueMissing(string $defaultLang, \Illuminate\Support\Collection $activeLanguages, string $field): array
    {
        $isTitle = $field === ServiceTranslationJob::FIELD_TITLE;

        $serviceKeys = ServiceTranslation::query()->where('lang', $defaultLang)->pluck('service_key');

        if ($serviceKeys->isEmpty() || $activeLanguages->isEmpty()) {
            return ['queued' => 0, 'retranslated' => 0];
        }

        $existing = ServiceTranslation::query()
            ->whereIn('service_key', $serviceKeys)
            ->whereIn('lang', $activeLanguages)
            ->get()
            ->keyBy(fn (ServiceTranslation $row) => $row->service_key.'|'.$row->lang);

        $pending = ServiceTranslationJob::query()
            ->whereIn('service_key', $serviceKeys)
            ->where('field', $field)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
            ->get()
            ->keyBy(fn (ServiceTranslationJob $job) => $job->service_key.'|'.$job->target_lang);

        $queued = 0;
        $retranslated = 0;

        foreach ($serviceKeys as $serviceKey) {
            foreach ($activeLanguages as $langCode) {
                $key = $serviceKey.'|'.$langCode;

                if ($pending->has($key)) {
                    continue;
                }

                $row = $existing->get($key);

                if ($row && ($isTitle ? $row->titleLooksTranslated() : $row->looksTranslated())) {
                    continue;
                }

                // A row that was translated before (has a translated_at/title_translated_at)
                // but no longer looksTranslated() only ever got there via
                // syncDefaultCatalog()'s "source changed" reset - i.e. this is a
                // re-translation, not the service's first one.
                $wasTranslatedBefore = $row && ($isTitle ? $row->title_translated_at !== null : $row->translated_at !== null);
                $trigger = $wasTranslatedBefore ? ServiceTranslationJob::TRIGGER_SOURCE_CHANGED : ServiceTranslationJob::TRIGGER_MISSING;

                ServiceTranslationJob::query()->updateOrCreate(
                    ['service_key' => $serviceKey, 'target_lang' => $langCode, 'field' => $field],
                    ['status' => ServiceTranslationJob::QUEUED, 'message' => null, 'trigger' => $trigger],
                );

                $queued++;

                if ($trigger === ServiceTranslationJob::TRIGGER_SOURCE_CHANGED) {
                    $retranslated++;
                }
            }
        }

        return ['queued' => $queued, 'retranslated' => $retranslated];
    }
}
