<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Url extends Model
{
    public const PATTERN_TYPES = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

    protected $fillable = [
        'source_url', 'path', 'lang', 'pattern_type', 'slug', 'group_key',
        'source_lastmod', 'priority', 'changefreq', 'is_hidden', 'is_manual', 'is_ai_guessed',
        'is_active', 'first_seen_at', 'last_seen_at',
        'translation_title', 'is_translated', 'translation_checked_at', 'translation_check_note', 'auto_hidden_for_translation',
        'site_update_override',
        'content_extracted_at', 'content_extraction_path',
        'article_title', 'seo_title', 'meta_description', 'meta_keywords',
        'og_title', 'og_description', 'twitter_title', 'twitter_description',
        'edited_content', 'edited_content_saved_at',
    ];

    protected function casts(): array
    {
        return [
            'source_lastmod' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_hidden' => 'boolean',
            'is_manual' => 'boolean',
            'is_ai_guessed' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'float',
            'is_translated' => 'boolean',
            'translation_checked_at' => 'datetime',
            'auto_hidden_for_translation' => 'boolean',
            'site_update_override' => 'boolean',
            'content_extracted_at' => 'datetime',
            'edited_content_saved_at' => 'datetime',
        ];
    }

    /**
     * True when this row clearly represents translated content - either explicitly flagged
     * (is_translated, set by BlogAiTranslationService after an AI translation, or by
     * BlogTranslationDetectionService after confirming one live) or, for a row nothing ever
     * explicitly checked, because real content has been extracted from it
     * (content_extraction_path). A non-default-language BLOG row only exists in the first place
     * because SyncService discovered a real live page there (a site that was already
     * multi-language before this admin ever used AI translation) or this tool created it, so
     * extracted content on it is already strong evidence on its own - is_translated is never set
     * by the plain "Extract content" action (BlogContentExtractionService), only by an actual
     * translation attempt or a live-site check, so a naturally-multi-language page that was only
     * ever extracted (never AI-translated, never Recheck'd) would otherwise read as "missing"
     * forever despite its content being sitting right there - see BlogTranslationQueue's several
     * "missing language" filters, all of which go through this rather than the raw column.
     */
    public function looksTranslated(): bool
    {
        return $this->is_translated === true || filled($this->content_extraction_path);
    }

    /**
     * True when this row is marked translated (looksTranslated()) but that's never actually been
     * confirmed against the live site, or was confirmed before the content it's based on last
     * changed - covering real gaps in is_translated on its own: BlogTranslationDetectionService's
     * hourly recheck leaves is_translated untouched on a fetch failure (translation_checked_at
     * stays at its old value) - which includes the common case of the guessed target URL simply
     * not existing live yet (a 404), not just transient errors.
     *
     * translation_checked_at alone isn't quite enough to trust either: BlogAiTranslationService
     * used to set it itself the instant a translation was generated (fixed, but topics translated
     * before that fix still carry the stale value it left behind - there was never a migration to
     * undo it retroactively). translation_title is the tell: a genuine live check (checkRow())
     * always sets it in the same write as translation_checked_at, so a row with the latter but
     * not the former was never actually fetched and confirmed - old bug or not.
     *
     * Lives here (not just in BlogTranslationQueue) so both it and the standalone Hidden
     * Translations list can compute the exact same verdict for a row without duplicating this
     * logic.
     */
    public function needsSiteUpdate(): bool
    {
        // An admin's own call always wins - added because comparing just the fetched <title> can
        // false-positive as "confirmed live" on a soft-404 (a site returning HTTP 200 with some
        // other title instead of a real 404 for a page that doesn't actually exist yet), which
        // the automatic checks below can't fully rule out for every site. See
        // BlogTranslationQueue::toggleSiteUpdateOverride().
        if ($this->site_update_override === true) {
            return true;
        }

        if (! $this->looksTranslated()) {
            return false;
        }

        if ($this->translation_checked_at === null || $this->translation_title === null) {
            return true;
        }

        return $this->content_extracted_at !== null && $this->content_extracted_at->gt($this->translation_checked_at);
    }
}
