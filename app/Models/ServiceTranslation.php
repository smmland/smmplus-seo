<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTranslation extends Model
{
    protected $fillable = [
        'service_key', 'lang', 'category_id', 'category_title', 'title',
        'description', 'description_text', 'source_description_hash',
        'is_translated', 'translated_at', 'live_confirmed_at', 'checked_at', 'check_note',
        'description_auto_retranslated_at', 'description_translated_from_hash',
        'is_title_translated', 'title_translated_at', 'title_live_confirmed_at', 'title_check_note',
        'title_auto_retranslated_at', 'title_translated_from_hash', 'source_title_hash',
        'first_seen_at', 'last_seen_at',
    ];

    // How long the "recently re-translated" marker (queue()'s badges, the details popup) stays
    // showing after an automatic re-translation - long enough to be seen without needing to
    // catch it same-day, short enough that it doesn't linger as stale trivia forever once the
    // admin's moved on.
    public const AUTO_RETRANSLATED_MARKER_DAYS = 7;

    protected function casts(): array
    {
        return [
            'is_translated' => 'boolean',
            'translated_at' => 'datetime',
            'live_confirmed_at' => 'datetime',
            'checked_at' => 'datetime',
            'description_auto_retranslated_at' => 'datetime',
            'is_title_translated' => 'boolean',
            'title_translated_at' => 'datetime',
            'title_live_confirmed_at' => 'datetime',
            'title_auto_retranslated_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    /**
     * True when this row clearly represents a real translation - mirrors Url::looksTranslated():
     * is_translated is null until a language's page has actually been fetched and compared
     * (ServiceCatalogService::refreshLanguage()), so a row that's never been checked yet reads as
     * "not translated" rather than crashing on a null comparison.
     */
    public function looksTranslated(): bool
    {
        return $this->is_translated === true;
    }

    /**
     * Mirrors Url::needsSiteUpdate(): true once translated but not yet confirmed live on the
     * real site - translated_at is only set when ServiceAiTranslationService saves a translation
     * here, live_confirmed_at only when ServiceCatalogService::refreshLanguage() finds the live
     * page's description actually differs from the default language's. A row translated
     * independently on the site itself (never went through this tool's AI) has no translated_at
     * at all, so it's never "needs update" - there's nothing of ours waiting to be uploaded.
     */
    public function needsSiteUpdate(): bool
    {
        if (! $this->looksTranslated() || $this->translated_at === null) {
            return false;
        }

        return $this->live_confirmed_at === null || $this->live_confirmed_at->lt($this->translated_at);
    }

    /**
     * The title counterpart to looksTranslated() - kept as its own separate flag rather than
     * reusing is_translated, since a service's title and description are translated
     * independently of each other.
     */
    public function titleLooksTranslated(): bool
    {
        return $this->is_title_translated === true;
    }

    /**
     * The title counterpart to needsSiteUpdate(), same is-it-uploaded-yet comparison against
     * title_translated_at/title_live_confirmed_at instead of translated_at/live_confirmed_at.
     */
    public function titleNeedsSiteUpdate(): bool
    {
        if (! $this->titleLooksTranslated() || $this->title_translated_at === null) {
            return false;
        }

        return $this->title_live_confirmed_at === null || $this->title_live_confirmed_at->lt($this->title_translated_at);
    }

    public function wasRecentlyAutoRetranslatedDescription(): bool
    {
        return $this->description_auto_retranslated_at !== null
            && $this->description_auto_retranslated_at->gt(now()->subDays(self::AUTO_RETRANSLATED_MARKER_DAYS));
    }

    public function wasRecentlyAutoRetranslatedTitle(): bool
    {
        return $this->title_auto_retranslated_at !== null
            && $this->title_auto_retranslated_at->gt(now()->subDays(self::AUTO_RETRANSLATED_MARKER_DAYS));
    }
}
