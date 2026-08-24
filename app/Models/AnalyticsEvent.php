<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id',
        'site_id',
        'visitor_id',
        'session_id',
        'event_name',
        'page_path',
        'page_title',
        'page_type',
        'is_landing',
        'language',
        'referrer_host',
        'source',
        'medium',
        'campaign',
        'device_type',
        'viewport_width',
        'country_code',
        'duration_ms',
        'scroll_depth',
        'metric_value',
        'target',
        'metadata',
        'daily_client_hash',
        'occurred_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'metric_value' => 'float',
            'is_landing' => 'boolean',
        ];
    }
}
