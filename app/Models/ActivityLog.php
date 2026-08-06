<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable audit trail entry - who did what, when, from where. Never updated after creation
 * (no $timestamps update column - see the migration), only ever inserted by
 * ActivityLogService::record() and read back on the Activity Log page.
 */
class ActivityLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'user_name', 'action', 'subject_type', 'subject_id',
        'subject_label', 'changes', 'section', 'ip_address', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
