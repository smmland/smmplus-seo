<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryTranslation extends Model
{
    protected $fillable = [
        'category_id', 'lang', 'title', 'source_title_hash', 'title_translated_from_hash',
        'is_translated', 'translated_at', 'live_confirmed_at', 'auto_retranslated_at',
        'check_note', 'checked_at', 'first_seen_at', 'last_seen_at',
    ];

    // Same reasoning/window as ServiceTranslation::AUTO_RETRANSLATED_MARKER_DAYS.
    public const AUTO_RETRANSLATED_MARKER_DAYS = 7;

    protected function casts(): array
    {
        return [
            'is_translated' => 'boolean',
            'translated_at' => 'datetime',
            'live_confirmed_at' => 'datetime',
            'auto_retranslated_at' => 'datetime',
            'checked_at' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    // Mirrors ServiceTranslation::titleLooksTranslated().
    public function looksTranslated(): bool
    {
        return $this->is_translated === true;
    }

    // Mirrors ServiceTranslation::titleNeedsSiteUpdate().
    public function needsSiteUpdate(): bool
    {
        if (! $this->looksTranslated() || $this->translated_at === null) {
            return false;
        }

        return $this->live_confirmed_at === null || $this->live_confirmed_at->lt($this->translated_at);
    }

    public function wasRecentlyAutoRetranslated(): bool
    {
        return $this->auto_retranslated_at !== null
            && $this->auto_retranslated_at->gt(now()->subDays(self::AUTO_RETRANSLATED_MARKER_DAYS));
    }
}
