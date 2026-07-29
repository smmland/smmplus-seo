<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayRequestLog extends Model
{
    public const STATUS_SUCCESS = 'success';
    public const STATUS_BLOCKED_IP = 'blocked_ip';
    public const STATUS_INVALID_ORIGIN = 'invalid_origin';
    public const STATUS_MISSING_SERVICE = 'missing_service';
    public const STATUS_INVALID_SERVICE = 'invalid_service';
    public const STATUS_MISSING_LINK = 'missing_link';
    public const STATUS_GLOBAL_IP_LIMIT = 'global_ip_limit';
    public const STATUS_GLOBAL_TARGET_LIMIT = 'global_target_limit';
    public const STATUS_IP_LIMIT = 'ip_limit';
    public const STATUS_TARGET_LIMIT = 'target_limit';
    public const STATUS_BELOW_MIN_QUANTITY = 'below_min_quantity';
    public const STATUS_UPSTREAM_ERROR = 'upstream_error';

    public $timestamps = false;

    protected $fillable = [
        'ip',
        'origin',
        'service_slug',
        'gateway_upstream_id',
        'target',
        'link',
        'quantity_requested',
        'quantity_allowed',
        'status',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $log) {
            $log->created_at ??= now();
        });
    }

    public function upstream(): BelongsTo
    {
        return $this->belongsTo(GatewayUpstream::class, 'gateway_upstream_id');
    }
}
