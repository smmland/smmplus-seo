<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayService extends Model
{
    use LogsActivity;

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

    public function activityLabel(): string
    {
        return $this->slug;
    }

    public function activitySection(): ?string
    {
        return PanelSection::FREE_SERVICE;
    }
}
