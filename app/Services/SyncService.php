<?php

namespace App\Services;

use App\Models\SyncRun;
use App\Models\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncService
{
    // A cache lock instead of an in-memory flag: artisan runs as a fresh process each
    // invocation (cron-triggered), so "is a sync running" state can't live in memory.
    private const LOCK_KEY = 'sitemap-sync-lock';

    public function __construct(
        private readonly SitemapFetcherService $fetcher,
        private readonly UrlClassifierService $classifier,
        private readonly SettingsService $settings,
    ) {}

    public function isRunning(): bool
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        if ($lock->get()) {
            // Nobody was holding it - we just grabbed it ourselves, so release immediately.
            $lock->release();

            return false;
        }

        return true;
    }

    /**
     * @return array{sync_run_id:int, total_fetched:int, added:int, updated:int, removed:int}
     */
    public function runSync(): array
    {
        $lock = Cache::lock(self::LOCK_KEY, 600);
        if (! $lock->get()) {
            throw new \RuntimeException('A sync is already in progress');
        }

        try {
            return $this->doSync();
        } finally {
            $lock->release();
        }
    }

    private function doSync(): array
    {
        $syncRun = SyncRun::query()->create(['status' => SyncRun::RUNNING]);
        Log::info("Sync run {$syncRun->id} started");

        try {
            $sourceUrl = $this->settings->getSourceSitemapUrl();
            $fetched = $this->fetcher->fetchAll($sourceUrl);

            $seenSourceUrls = [];
            $added = 0;
            $updated = 0;

            foreach ($fetched as $entry) {
                $seenSourceUrls[] = $entry['loc'];
                $classified = $this->classifier->classify($entry['loc']);
                $lastmod = $entry['lastmod'] ? new \DateTimeImmutable($entry['lastmod']) : null;

                $existing = Url::query()->where('source_url', $entry['loc'])->first();

                if (! $existing) {
                    Url::query()->create([
                        'source_url' => $entry['loc'],
                        'path' => $classified['path'],
                        'lang' => $classified['lang'],
                        'pattern_type' => $classified['pattern_type'],
                        'slug' => $classified['slug'],
                        'group_key' => $classified['group_key'],
                        'source_lastmod' => $lastmod,
                        'is_hidden' => $classified['default_hidden'],
                        'is_active' => true,
                        'first_seen_at' => now(),
                        'last_seen_at' => now(),
                    ]);
                    $added++;
                } else {
                    // Manually-recategorized URLs keep the admin's choice; only re-classify untouched ones.
                    $existing->update([
                        'path' => $classified['path'],
                        'lang' => $classified['lang'],
                        'pattern_type' => $existing->is_manual ? $existing->pattern_type : $classified['pattern_type'],
                        'slug' => $classified['slug'],
                        'group_key' => $existing->is_manual ? $existing->group_key : $classified['group_key'],
                        'source_lastmod' => $lastmod,
                        'is_active' => true,
                        'last_seen_at' => now(),
                    ]);
                    $updated++;
                }
            }

            // Anything previously fetched but absent from this run has disappeared from the source sitemap.
            // We don't delete it (an admin may have opinions about it); we just flag it inactive.
            //
            // is_ai_guessed rows (BlogAiTranslationService::saveTranslation(),
            // BlogTranslationDetectionService::checkMissingLanguage()) are a permanent exception:
            // their source_url is a *guessed* URL pattern, never something pulled from a real
            // sitemap entry, so it's expected to keep being absent from every future sitemap
            // fetch too - confirmed live or not. An earlier version of this exclusion only
            // covered not-yet-confirmed rows (translation_checked_at null), which meant a
            // translation would survive right up until BlogTranslationDetectionService's hourly
            // check confirmed it live - at which point the very next sync deactivated it anyway,
            // since its guessed URL still wasn't a real sitemap entry. That silently hid
            // confirmed translations a few hours after they were made, which is exactly the "my
            // translations keep disappearing" symptom this fixes for good.
            $pruneQuery = Url::query()
                ->where('is_active', true)
                ->whereNotIn('source_url', $seenSourceUrls);

            // Guarded: on a host with no terminal access, this column can lag behind this code
            // until "Update database" is clicked - degrades to the old (bug-affected) behavior
            // for that window rather than a hard SQL error on an unknown column.
            if (Schema::hasColumn('urls', 'is_ai_guessed')) {
                $pruneQuery->where(function ($query) {
                    $query->where('is_ai_guessed', '!=', true)
                        ->orWhereNull('is_ai_guessed');
                });
            }

            $removed = $pruneQuery->update(['is_active' => false]);

            $syncRun->update([
                'status' => SyncRun::SUCCESS,
                'finished_at' => now(),
                'total_fetched' => count($fetched),
                'added' => $added,
                'updated' => $updated,
                'removed' => $removed,
            ]);

            Log::info("Sync run {$syncRun->id} finished: fetched=" . count($fetched) . " added={$added} updated={$updated} removed={$removed}");

            return [
                'sync_run_id' => $syncRun->id,
                'total_fetched' => count($fetched),
                'added' => $added,
                'updated' => $updated,
                'removed' => $removed,
            ];
        } catch (Throwable $e) {
            $syncRun->update([
                'status' => SyncRun::FAILED,
                'finished_at' => now(),
                'error_message' => $e->getMessage(),
            ]);
            Log::error("Sync run {$syncRun->id} failed: {$e->getMessage()}");
            throw $e;
        }
    }
}
