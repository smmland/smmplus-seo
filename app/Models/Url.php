<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'source_url', 'path', 'lang', 'pattern_type', 'slug', 'group_key',
    'source_lastmod', 'priority', 'changefreq', 'is_hidden', 'is_manual',
    'is_active', 'first_seen_at', 'last_seen_at',
])]
class Url extends Model
{
    public const PATTERN_TYPES = ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER'];

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
        ];
    }
}
