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

        $lang = trim((string) $request->query('lang', '')) ?: $this->defaultLang();

        // available=true only - a service smm.plus stopped selling since the last sync is simply
        // left out, not returned with a flag the frontend would have to remember to check.
        $matched = CatalogService::query()->where('available', true)->get()
            ->filter(fn (CatalogService $service) => $mapping->matches($service));

        $translations = ServiceTranslation::query()
            ->whereIn('service_key', $matched->pluck('service_id'))
            ->where('lang', $lang)
            ->get()
            ->keyBy('service_key');

        $currency = $this->catalogSettings->getCurrencySymbol();

        $services = $matched
            ->map(function (CatalogService $service) use ($mapping, $translations, $currency) {
                $translation = $translations->get($service->service_id);

                return [
                    'row' => $service,
                    'is_geo' => $mapping->isGeo($service),
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
                        'is_geo' => $mapping->isGeo($service),
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
