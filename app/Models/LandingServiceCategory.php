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
     * Whether a synced catalog service falls under this landing category - a case-insensitive
     * substring match against whichever of its category/name text this mapping was configured
     * to check.
     */
    public function matches(CatalogService $service): bool
    {
        $haystack = $this->match_field === self::MATCH_FIELD_NAME ? $service->name : $service->category;

        return $haystack !== null && str_contains(mb_strtolower($haystack), mb_strtolower($this->match_text));
    }

    /**
     * Null when this mapping has no GEO concept at all (geo_keyword unset) - distinct from
     * true/false, so LandingServicesController can tell "not applicable" apart from "confirmed
     * non-GEO" and ignore a ?geo= filter for a category that doesn't have the distinction.
     */
    public function isGeo(CatalogService $service): ?bool
    {
        if ($this->geo_keyword === null || $this->geo_keyword === '') {
            return null;
        }

        $haystack = $this->match_field === self::MATCH_FIELD_NAME ? $service->name : $service->category;

        return $haystack !== null && str_contains(mb_strtolower($haystack), mb_strtolower($this->geo_keyword));
    }
}
