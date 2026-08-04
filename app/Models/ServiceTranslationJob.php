<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceTranslationJob extends Model
{
    public const QUEUED = 'queued';
    public const RUNNING = 'running';
    public const DONE = 'done';
    public const FAILED = 'failed';

    public const PENDING_STATUSES = [self::QUEUED, self::RUNNING];

    public const FIELD_DESCRIPTION = 'description';
    public const FIELD_TITLE = 'title';

    protected $fillable = [
        'service_key', 'target_lang', 'field', 'status', 'message',
        'provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost_usd',
    ];

    protected $casts = [
        'input_tokens' => 'integer',
        'output_tokens' => 'integer',
        'estimated_cost_usd' => 'decimal:6',
    ];
}
