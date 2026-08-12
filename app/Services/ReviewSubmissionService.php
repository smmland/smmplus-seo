<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Review;

/**
 * Handles a publicly-submitted review: resolves which language it's written in from three
 * layered signals (most to least reliable - see resolveLang()), geolocates the submitter's IP
 * for a display country regardless of which language signal won, then saves the review as
 * unapproved so it only reaches the public API once an admin reviews it in the panel.
 */
class ReviewSubmissionService
{
    // Dominant language per country, limited to the languages this panel already has starter
    // reviews for (ReviewsController's defaultLang() fallback covers anything not listed here).
    // Necessarily approximate for multilingual countries (e.g. Canada, Switzerland) - left out
    // entirely rather than guessing wrong.
    private const COUNTRY_TO_LANG = [
        'IR' => 'fa', 'AF' => 'fa',
        'SA' => 'ar', 'EG' => 'ar', 'AE' => 'ar', 'IQ' => 'ar', 'JO' => 'ar', 'KW' => 'ar',
        'QA' => 'ar', 'BH' => 'ar', 'OM' => 'ar', 'YE' => 'ar', 'SY' => 'ar', 'LB' => 'ar',
        'LY' => 'ar', 'TN' => 'ar', 'DZ' => 'ar', 'MA' => 'ar', 'SD' => 'ar',
        'TR' => 'tr',
        'RU' => 'ru', 'BY' => 'ru', 'KZ' => 'ru', 'KG' => 'ru',
        'ES' => 'es', 'MX' => 'es', 'AR' => 'es', 'CO' => 'es', 'CL' => 'es', 'PE' => 'es',
        'VE' => 'es', 'EC' => 'es', 'GT' => 'es', 'CU' => 'es', 'BO' => 'es', 'DO' => 'es',
        'HN' => 'es', 'PY' => 'es', 'SV' => 'es', 'NI' => 'es', 'CR' => 'es', 'PA' => 'es', 'UY' => 'es',
        'FR' => 'fr',
        'DE' => 'de', 'AT' => 'de',
        'IT' => 'it',
        'BR' => 'bp', 'PT' => 'bp',
        'ID' => 'id',
        'KR' => 'ko',
        'CN' => 'zh', 'TW' => 'zh',
        'JP' => 'ja',
        'TH' => 'th',
        'VN' => 'vi',
        'PL' => 'pl',
        'UA' => 'uk',
        'US' => 'en', 'GB' => 'en', 'AU' => 'en', 'NZ' => 'en', 'IE' => 'en', 'ZA' => 'en',
        'IN' => 'en', 'PH' => 'en', 'NG' => 'en', 'KE' => 'en', 'PK' => 'en',
    ];

    public function __construct(
        private readonly IpGeolocationService $geolocation,
    ) {}

    /**
     * @param  array{author_name: string, rating: int, body: string, related_service: ?string, username: string, user_id: ?string, order_id: ?string, ticket_id: ?string, csrf_token: ?string, reported_ip: ?string, user_agent: ?string, lang: ?string}  $data
     */
    public function submit(array $data, string $ip, ?string $acceptLanguageHeader = null): Review
    {
        $geo = $this->geolocation->lookup($ip);
        $lang = $this->resolveLang($data['lang'] ?? null, $acceptLanguageHeader, $geo['countryCode']);

        $nextSortOrder = 1 + (int) (Review::query()->where('lang', $lang)->max('sort_order') ?? -1);

        return Review::create([
            'lang' => $lang,
            'author_name' => $data['author_name'],
            'rating' => $data['rating'],
            'body' => $data['body'],
            'related_service' => $data['related_service'] ?? null,
            'country_name' => $geo['countryName'],
            'country_code' => $geo['countryCode'],
            'is_approved' => false,
            'sort_order' => $nextSortOrder,
            // Panel-only moderation metadata - never returned by the public GET endpoint (see
            // ReviewsController::index()'s response mapping, which doesn't include any of these).
            'submitted_username' => $data['username'],
            'submitted_ip' => $ip,
            // Captured as-is, not currently validated against anything - see the migration's
            // comment for why (no shared session with the site to check the csrf_token against,
            // no order/ticket-ownership check wired up yet).
            'frontend_user_id' => $data['user_id'] ?? null,
            'frontend_order_id' => $data['order_id'] ?? null,
            'frontend_ticket_id' => $data['ticket_id'] ?? null,
            'frontend_csrf_token' => $data['csrf_token'] ?? null,
            'reported_ip' => $data['reported_ip'] ?? null,
            'user_agent' => $data['user_agent'] ?? null,
        ]);
    }

    /**
     * Three signals, most to least reliable:
     *
     * 1. An explicit `lang` from the frontend - it already knows which language page the visitor
     *    is on (e.g. smm.plus/en/...), which is ground truth rather than a guess. Only trusted
     *    when it names a language actually configured active here, so a typo or a made-up code
     *    can't land a review under a language nobody serves.
     * 2. The browser's Accept-Language header - not perfect (some users never change it from a
     *    browser they installed in another region), but still a direct signal about the visitor
     *    rather than an inference through their IP.
     * 3. IP -> country -> language (COUNTRY_TO_LANG) - the original fallback, kept last since
     *    country tells you nothing when the visitor is on a VPN/proxy, and even a correct country
     *    is still one inferential step removed from the language they actually read.
     *
     * Falls through to the site's default language only once all three come up empty.
     */
    private function resolveLang(?string $explicitLang, ?string $acceptLanguageHeader, ?string $countryCode): string
    {
        $activeCodes = Language::query()->where('is_active', true)->pluck('code')->all();

        if ($explicitLang && in_array($explicitLang, $activeCodes, true)) {
            return $explicitLang;
        }

        foreach ($this->parseAcceptLanguage($acceptLanguageHeader) as $code) {
            if (in_array($code, $activeCodes, true)) {
                return $code;
            }
        }

        // Unlike the two signals above (external input that needs checking against what's
        // actually configured), COUNTRY_TO_LANG is our own curated mapping - its output is
        // trusted directly, same as before this method existed.
        if ($countryCode && isset(self::COUNTRY_TO_LANG[$countryCode])) {
            return self::COUNTRY_TO_LANG[$countryCode];
        }

        return $this->defaultLang();
    }

    /**
     * Standard Accept-Language parsing ("en-US,en;q=0.9,fa;q=0.8") - returns base language codes
     * (region subtag dropped: "en-US" -> "en") in descending preference order, deduplicated.
     *
     * @return list<string>
     */
    private function parseAcceptLanguage(?string $header): array
    {
        if (! $header) {
            return [];
        }

        $entries = [];

        foreach (explode(',', $header) as $part) {
            $segments = explode(';', trim($part));
            $tag = trim($segments[0]);

            if ($tag === '' || $tag === '*') {
                continue;
            }

            $quality = 1.0;

            foreach (array_slice($segments, 1) as $segment) {
                $segment = trim($segment);

                if (str_starts_with($segment, 'q=')) {
                    $quality = (float) substr($segment, 2);
                }
            }

            $entries[] = ['code' => strtolower(explode('-', $tag)[0]), 'quality' => $quality];
        }

        usort($entries, fn (array $a, array $b) => $b['quality'] <=> $a['quality']);

        $codes = [];

        foreach ($entries as $entry) {
            if (! in_array($entry['code'], $codes, true)) {
                $codes[] = $entry['code'];
            }
        }

        return $codes;
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'fa';
    }
}
