<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayService extends Model
{
    protected $fillable = [
        'gateway_upstream_id',
        'slug',
        'label',
        'upstream_service_id',
        'min_quantity',
        'max_quantity',
        'limit_seconds',
        'ip_limit',
        'target_limit',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function upstream(): BelongsTo
    {
        return $this->belongsTo(GatewayUpstream::class, 'gateway_upstream_id');
    }
}
