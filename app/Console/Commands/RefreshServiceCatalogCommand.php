<?php

namespace App\Console\Commands;

use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Services\ServiceCatalogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Runs every 12 hours (routes/console.php) - re-syncs the default-language services catalog
 * (picking up new/changed services), re-checks every active language's live page for what's
 * already genuinely translated, then auto-queues AI translation for whatever's still missing.
 * Unlike blog topics, there's no separate "extract content" step to gate this behind: a
 * service's description is short plain text pulled directly off the shared listing page, not a
 * whole article that needs image downloads and style conversion first.
 */
class RefreshServiceCatalogCommand extends Command
{
    protected $signature = 'services:refresh-catalog';

    protected $description = 'Re-syncs the default-language services catalog, checks translation status per language, and queues missing AI translations';

    public function handle(ServiceCatalogService $catalog): int
    {
        $sync = $catalog->syncDefaultCatalog();

        if (! $sync['ok']) {
            $this->error('Could not sync the default-language catalog: '.$sync['error']);

            return self::FAILURE;
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

        $queued = Schema::hasTable('service_translation_jobs')
            ? $this->queueMissingTranslations($defaultLang, $activeLanguages)
            : 0;

        $this->info("Synced {$sync['total']} service(s) ({$sync['new']} new, {$sync['changed']} changed). Checked {$totalChecked}, translated {$totalTranslated}, queued {$queued}.");

        return self::SUCCESS;
    }

    /**
     * Same "missing = no row yet, or exists but not looksTranslated(), and not already queued"
     * shape AutoProcessNewBlogsCommand uses for blog topics - queues every (service, language)
     * pair that's neither confirmed translated nor already mid-flight.
     */
    private function queueMissingTranslations(string $defaultLang, \Illuminate\Support\Collection $activeLanguages): int
    {
        $serviceKeys = ServiceTranslation::query()->where('lang', $defaultLang)->pluck('service_key');

        if ($serviceKeys->isEmpty() || $activeLanguages->isEmpty()) {
            return 0;
        }

        $existing = ServiceTranslation::query()
            ->whereIn('service_key', $serviceKeys)
            ->whereIn('lang', $activeLanguages)
            ->get()
            ->keyBy(fn (ServiceTranslation $row) => $row->service_key.'|'.$row->lang);

        $pending = ServiceTranslationJob::query()
            ->whereIn('service_key', $serviceKeys)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
            ->get()
            ->keyBy(fn (ServiceTranslationJob $job) => $job->service_key.'|'.$job->target_lang);

        $queued = 0;

        foreach ($serviceKeys as $serviceKey) {
            foreach ($activeLanguages as $langCode) {
                $key = $serviceKey.'|'.$langCode;

                if ($pending->has($key)) {
                    continue;
                }

                $row = $existing->get($key);

                if ($row && $row->looksTranslated()) {
                    continue;
                }

                ServiceTranslationJob::query()->updateOrCreate(
                    ['service_key' => $serviceKey, 'target_lang' => $langCode],
                    ['status' => ServiceTranslationJob::QUEUED, 'message' => null],
                );

                $queued++;
            }
        }

        return $queued;
    }
}
