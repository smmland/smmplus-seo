<?php

namespace App\Services;

use App\Models\CatalogService;
use App\Models\Language;
use App\Models\LandingServiceCategory;
use App\Models\ServiceTranslation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

/**
 * Syncs the cached retail catalog (catalog_services) from smm.plus's own customer-facing API -
 * POST {upstream base_url} with action=services - the real, currently-billed price/min/max shown
 * to real customers, per the documented contract at https://smm.plus/api. The base URL and key
 * come from an existing Free Service Gateway > API Server (GatewayUpstream, e.g. an "SMM Plus
 * Main" row with base_url https://smm.plus/api/v2) chosen on the SEO Settings page, rather than a
 * second copy of the same credentials typed here. Distinct from ServiceCatalogService, which
 * scrapes the HTML /services page for name/description only and has no pricing at all.
 *
 * The API returns "name" in only one language (whatever the upstream account is set to) with no
 * per-language variant and no description at all - so after every sync, any service matched by an
 * active LandingServiceCategory gets a default-language service_translations row seeded from that
 * name if one doesn't already exist. That's the exact table/queue RefreshServiceCatalogCommand's
 * hourly services:refresh-catalog run already scans (queueMissing()) to auto-queue AI translation
 * into every active language - reusing it here means a brand-new, API-only service (never seen by
 * the HTML scraper) still gets translated everywhere automatically, with no separate translation
 * pipeline to build or pay for beyond the one that already exists. A service the scraper already
 * knows about keeps its richer scraped title/description untouched (firstOrCreate never
 * overwrites an existing row).
 */
class CatalogSyncService
{
    private const FIELDS = ['name', 'type', 'category', 'rate', 'min', 'max', 'refill', 'cancel'];

    public function __construct(private readonly CatalogSettingsService $settings) {}

    /**
     * @return array{ok: bool, error?: string, total?: int, added?: int, changed?: int, unavailable?: int, seededTranslations?: int}
     */
    public function sync(): array
    {
        $upstream = $this->settings->getUpstream();

        if (! $upstream) {
            return ['ok' => false, 'error' => 'No API server selected yet - pick one on SEO > Settings (managed on Free Service Gateway > API Servers).'];
        }

        try {
            $response = Http::asForm()->timeout(30)->post($upstream->base_url, [
                'key' => $upstream->api_key,
                'action' => 'services',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Fetch error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'HTTP '.$response->status().' from '.$upstream->base_url];
        }

        $data = $response->json();

        if (is_array($data) && isset($data['error'])) {
            return ['ok' => false, 'error' => (string) $data['error']];
        }

        if (! is_array($data) || ! array_is_list($data)) {
            return ['ok' => false, 'error' => 'Unexpected response shape from smm.plus\'s API (expected a JSON array of services).'];
        }

        if (count($data) === 0) {
            // Never seen a genuinely empty catalog from a live panel - far more likely a
            // transient upstream glitch, so this leaves the existing cache untouched rather than
            // marking every service unavailable off one suspicious response.
            return ['ok' => false, 'error' => 'smm.plus returned zero services - leaving the cached catalog untouched.'];
        }

        $touchedIds = [];
        $added = 0;
        $changed = 0;
        $now = now();

        foreach ($data as $entry) {
            if (! is_array($entry) || ! isset($entry['service'])) {
                continue;
            }

            $serviceId = (string) $entry['service'];
            $touchedIds[] = $serviceId;

            $row = CatalogService::query()->firstOrNew(['service_id' => $serviceId]);
            $isNew = ! $row->exists;
            $before = $row->exists ? $row->only(self::FIELDS) : null;

            $row->name = (string) ($entry['name'] ?? '');
            $row->type = isset($entry['type']) ? (string) $entry['type'] : null;
            $row->category = isset($entry['category']) ? (string) $entry['category'] : null;
            $row->rate = isset($entry['rate']) ? (string) $entry['rate'] : null;
            $row->min = isset($entry['min']) ? (int) $entry['min'] : null;
            $row->max = isset($entry['max']) ? (int) $entry['max'] : null;
            $row->refill = (bool) ($entry['refill'] ?? false);
            $row->cancel = (bool) ($entry['cancel'] ?? false);
            $row->available = true;
            $row->synced_at = $now;
            $row->save();

            if ($isNew) {
                $added++;
            } elseif ($before !== $row->only(self::FIELDS)) {
                $changed++;
            }
        }

        $unavailable = CatalogService::query()
            ->where('available', true)
            ->whereNotIn('service_id', $touchedIds)
            ->update(['available' => false]);

        $seededTranslations = $this->seedTranslationsForLandingCategories();

        return [
            'ok' => true,
            'total' => count($touchedIds),
            'added' => $added,
            'changed' => $changed,
            'unavailable' => $unavailable,
            'seededTranslations' => $seededTranslations,
        ];
    }

    /**
     * Default-language-only - other languages are filled in by the existing AI translation queue
     * (RefreshServiceCatalogCommand::queueMissing(), services:process-queue) on its normal hourly
     * cadence, same as every other service. Limited to services matched by at least one active
     * LandingServiceCategory - the only ones ever exposed through the public API - so this never
     * queues (and pays AI translation cost for) the rest of a large upstream catalog nobody's
     * landing pages actually use.
     */
    private function seedTranslationsForLandingCategories(): int
    {
        if (! Schema::hasTable('landing_service_categories') || ! Schema::hasTable('service_translations')) {
            return 0;
        }

        $mappings = LandingServiceCategory::query()->where('is_active', true)->get();

        if ($mappings->isEmpty()) {
            return 0;
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        // No translation exists yet for a service seen here for the first time - matching can
        // only use the raw, single-language text the pricing API just returned. Once this seed
        // row exists and gets AI-translated, LandingServicesController's own matching switches to
        // the translated default-language text instead (see its referenceTexts()).
        $matched = CatalogService::query()->where('available', true)->get()
            ->filter(function (CatalogService $service) use ($mappings) {
                return $mappings->contains(function (LandingServiceCategory $mapping) use ($service) {
                    $raw = $mapping->matchField() === LandingServiceCategory::MATCH_FIELD_NAME ? $service->name : $service->category;

                    return $mapping->matchesAny([$raw]);
                });
            });

        $seeded = 0;

        foreach ($matched as $service) {
            $row = ServiceTranslation::query()->firstOrCreate(
                ['service_key' => $service->service_id, 'lang' => $defaultLang],
                [
                    'category_title' => $service->category,
                    'title' => $service->name,
                    'checked_at' => now(),
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ],
            );

            if ($row->wasRecentlyCreated) {
                $seeded++;
            }
        }

        return $seeded;
    }
}
