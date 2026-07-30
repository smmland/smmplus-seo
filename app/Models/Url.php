<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Url extends Model
{
    public const PATTERN_TYPES = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

    protected $fillable = [
        'source_url', 'path', 'lang', 'pattern_type', 'slug', 'group_key',
        'source_lastmod', 'priority', 'changefreq', 'is_hidden', 'is_manual',
        'is_active', 'first_seen_at', 'last_seen_at',
        'translation_title', 'is_translated', 'translation_checked_at', 'translation_check_note', 'auto_hidden_for_translation',
        'content_extracted_at', 'content_extraction_path',
    ];

    protected function casts(): array
    {
        return [
            'source_lastmod' => 'datetime',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_hidden' => 'boolean',
            'is_manual' => 'boolean',
            'is_active' => 'boolean',
            'priority' => 'float',
            'is_translated' => 'boolean',
            'translation_checked_at' => 'datetime',
            'auto_hidden_for_translation' => 'boolean',
            'content_extracted_at' => 'datetime',
        ];
    }
}
