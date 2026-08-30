<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LandingServiceCategory extends Model
{
    public const MATCH_FIELD_CATEGORY = 'category';

    public const MATCH_FIELD_NAME = 'name';

    protected $fillable = [
        'slug', 'label', 'match_field', 'match_text', 'geo_keyword', 'is_active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * Whether any of the given candidate texts (case-insensitive) contains match_text - the
     * caller decides which field (category/name, per matchField()) and which language(s) each
     * candidate came from. Matching is deliberately language-agnostic here: the admin's match_text
     * is checked against a single, predictable language, never whatever language the pricing
     * API's account happens to be set to - see LandingServicesController::referenceTexts() and
     * CatalogSyncService's seeding step for the two ways candidates get built.
     */
    public function matchesAny(array $texts): bool
    {
        foreach ($texts as $text) {
            if ($text !== null && str_contains(mb_strtolower($text), mb_strtolower($this->match_text))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Null when this mapping has no GEO concept at all (geo_keyword unset) - distinct from
     * true/false, so LandingServicesController can tell "not applicable" apart from "confirmed
     * non-GEO" and ignore a ?geo= filter for a category that doesn't have the distinction.
     */
    public function isGeoAny(array $texts): ?bool
    {
        if ($this->geo_keyword === null || $this->geo_keyword === '') {
            return null;
        }

        foreach ($texts as $text) {
            if ($text !== null && str_contains(mb_strtolower($text), mb_strtolower($this->geo_keyword))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Which field (category or name) of a candidate row/service this mapping checks - lets a
     * caller pick the right property off both ServiceTranslation rows and a raw CatalogService.
     */
    public function matchField(): string
    {
        return $this->match_field;
    }
}
