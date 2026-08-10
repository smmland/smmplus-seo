<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlogTranslationJob extends Model
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    // Still "in progress" from the panel's point of view - queued and running are only ever
    // shown to the admin as a single "translating..." state, the distinction just helps tell
    // "waiting for the queue worker's next tick" apart from "actually mid-translation" when
    // debugging.
    public const PENDING_STATUSES = [self::QUEUED, self::RUNNING];

    protected $fillable = [
        'group_key', 'target_lang', 'status', 'message',
        'provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost_usd',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
    ];
}
