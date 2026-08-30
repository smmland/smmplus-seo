<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CatalogService;
use App\Models\Language;
use App\Models\LandingServiceCategory;
use App\Models\ServiceTranslation;
use App\Services\CatalogSettingsService;
use App\Services\GatewaySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public, read-only catalog for SEO landing pages that currently hardcode price/Service
 * ID/min/max (e.g. /telegram-premium-bot-start) - serves the cached copy of smm.plus's own retail
 * catalog (CatalogSyncService) filtered down to one admin-configured logical category
 * (LandingServiceCategory), instead of every page hardcoding numbers that drift out of date.
 *
 * Matching a service against a LandingServiceCategory's match_text is deliberately independent of
 * the response language (?lang=): the pricing API's raw category/name text is in whatever single
 * language the upstream account happens to be set to, which an admin typing match_text can't rely
 * on staying consistent - so matching checks the service's own English and site-default-language
 * translations (ServiceTranslation, populated by the existing HTML-scrape/AI-translation
 * pipeline - see CatalogSyncService's seeding step), falling back to the raw API text only when
 * neither translation exists yet. The name/description actually returned in the response still
 * follows ?lang= exactly as before - only the matching step is pinned to a predictable language.
 *
 * Unlike ReviewsController::index() (open to any origin - nothing here worth restricting), this
 * exposes real pricing/service IDs, so it's restricted to the same allowed-origins list
 * (Security Settings) the free-service/giveaway gateway uses - GET requests don't trigger a CORS
 * preflight, so no OPTIONS route/middleware stack is needed, just the response header itself.
 */
class LandingServicesController extends Controller
{
    public function __construct(
        private readonly GatewaySettingsService $corsSettings,
        private readonly CatalogSettingsService $catalogSettings,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $origin = $request->headers->get('Origin');

        $slug = trim((string) $request->query('category', ''));

        if ($slug === '') {
            return $this->respond(['ok' => false, 'error' => 'The "category" parameter is required.'], 400, $origin);
        }

        $mapping = LandingServiceCategory::query()->where('slug', $slug)->where('is_active', true)->first();

        if (! $mapping) {
            return $this->respond(['ok' => false, 'error' => 'Unknown category.'], 404, $origin);
        }

        $geoParam = $request->query('geo');
        $geoFilter = $geoParam === null ? null : filter_var($geoParam, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        $defaultLang = $this->defaultLang();
        $lang = trim((string) $request->query('lang', '')) ?: $defaultLang;

        // available=true only - a service smm.plus stopped selling since the last sync is simply
        // left out, not returned with a flag the frontend would have to remember to check.
        $available = CatalogService::query()->where('available', true)->get();

        // English + the site's default language (often the same) - see the class docblock for
        // why matching never uses the raw pricing-API text's language directly.
        $referenceLangs = array_values(array_unique(array_filter(['en', $defaultLang])));

        $referenceTranslations = ServiceTranslation::query()
            ->whereIn('service_key', $available->pluck('service_id'))
            ->whereIn('lang', $referenceLangs)
            ->get()
            ->groupBy('service_key');

        $matched = $available->filter(fn (CatalogService $service) => $mapping->matchesAny($this->referenceTexts($mapping, $service, $referenceTranslations)));

        $translations = ServiceTranslation::query()
            ->whereIn('service_key', $matched->pluck('service_id'))
            ->where('lang', $lang)
            ->get()
            ->keyBy('service_key');

        $currency = $this->catalogSettings->getCurrencySymbol();

        $services = $matched
            ->map(function (CatalogService $service) use ($mapping, $translations, $referenceTranslations, $currency) {
                $translation = $translations->get($service->service_id);
                $isGeo = $mapping->isGeoAny($this->referenceTexts($mapping, $service, $referenceTranslations));

                return [
                    'is_geo' => $isGeo,
                    'payload' => [
                        'id' => $service->service_id,
                        'name' => $translation?->title ?: $service->name,
                        'description' => $translation?->description_text,
                        'rate' => $service->rate,
                        'rate_formatted' => $service->rate !== null ? $currency.number_format((float) $service->rate, 2).' / 1000' : null,
                        'min' => $service->min,
                        'max' => $service->max,
                        'refill' => $service->refill,
                        'cancel' => $service->cancel,
                        'is_geo' => $isGeo,
                        // Admin-typed only (Catalog Services list) - never inferred, since
                        // neither smm.plus's API nor the scraped HTML says where a service's
                        // delivery actually originates. Null unless an admin has explicitly set
                        // it (e.g. exactly "Telegram Search").
                        'start_source' => $service->source_label,
                        // No real data source anywhere for this (not in smm.plus's API, not in
                        // the HTML scrape) - omitted rather than guessed. Set source_label-style
                        // admin overrides if this needs to be real in the future.
                        // 'average_time' intentionally left out.
                    ],
                ];
            })
            ->when($geoFilter !== null, fn ($rows) => $rows->filter(fn (array $row) => $row['is_geo'] === $geoFilter))
            ->map(fn (array $row) => $row['payload'])
            ->values();

        return $this->respond([
            'ok' => true,
            'category' => $slug,
            'lang' => $lang,
            'synced_at' => optional($matched->max('synced_at'))->toIso8601String(),
            'services' => $services,
        ], 200, $origin);
    }

    /**
     * Every candidate string worth checking a service against: its English/default-language
     * translation of whichever field the mapping cares about (category or name), plus the raw
     * pricing-API field as a last resort for a service not translated yet at all. Order doesn't
     * matter - matchesAny()/isGeoAny() just need to find one hit.
     *
     * @param  \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, ServiceTranslation>>  $referenceTranslations
     * @return list<?string>
     */
    private function referenceTexts(LandingServiceCategory $mapping, CatalogService $service, $referenceTranslations): array
    {
        $field = $mapping->matchField() === LandingServiceCategory::MATCH_FIELD_NAME ? 'title' : 'category_title';

        $fromTranslations = $referenceTranslations->get($service->service_id, collect())
            ->map(fn (ServiceTranslation $row) => $row->{$field})
            ->all();

        $raw = $mapping->matchField() === LandingServiceCategory::MATCH_FIELD_NAME ? $service->name : $service->category;

        return [...$fromTranslations, $raw];
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    private function respond(array $payload, int $status, ?string $origin): JsonResponse
    {
        $response = response()->json($payload, $status);

        if ($origin !== null && $origin !== '' && in_array($origin, $this->corsSettings->getAllowedOrigins(), true)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Vary', 'Origin');
        }

        return $response;
    }
}
