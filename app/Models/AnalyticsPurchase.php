<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsPurchase extends Model
{
    public const REVENUE_STATUSES = ['paid', 'partially_refunded', 'refunded'];

    protected $fillable = [
        'site_id',
        'external_order_id',
        'last_event_id',
        'status',
        'gross_amount',
        'refunded_amount',
        'currency',
        'visitor_id',
        'session_id',
        'landing_page',
        'language',
        'source',
        'medium',
        'campaign',
        'device_type',
        'user_state',
        'country_code',
        'paid_at',
        'source_updated_at',
    ];

    protected function casts(): array
    {
        return [
            'gross_amount' => 'decimal:6',
            'refunded_amount' => 'decimal:6',
            'paid_at' => 'datetime',
            'source_updated_at' => 'datetime',
        ];
    }
}
