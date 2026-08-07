<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CategoryTranslationJob extends Model
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public const PENDING_STATUSES = [self::QUEUED, self::RUNNING];

    public const TRIGGER_MISSING = 'missing';
    public const TRIGGER_SOURCE_CHANGED = 'source_changed';

    protected $fillable = [
        'category_id', 'target_lang', 'trigger', 'status', 'message',
        'provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost_usd',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
    ];
}
