<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTranslation extends Model
{
    protected $fillable = [
        'service_key', 'lang', 'category_id', 'category_title', 'title',
        'description', 'description_text', 'source_description_hash',
        'is_translated', 'checked_at', 'check_note',
        'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'is_translated' => 'boolean',
            'checked_at' => 'datetime',
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
}
