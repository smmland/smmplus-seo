<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Review;

/**
 * Handles a publicly-submitted review: geolocates the submitter's IP for country + a best-guess
 * language (there's no reliable way to know a visitor's language from their IP alone - country is
 * the closest signal available, mapped through COUNTRY_TO_LANG below), then saves it as
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
     * @param  array{author_name: string, rating: int, body: string, related_service: ?string}  $data
     */
    public function submit(array $data, string $ip): Review
    {
        $geo = $this->geolocation->lookup($ip);
        $lang = ($geo['countryCode'] && isset(self::COUNTRY_TO_LANG[$geo['countryCode']]))
            ? self::COUNTRY_TO_LANG[$geo['countryCode']]
            : $this->defaultLang();

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
        ]);
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'fa';
    }
}
