<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TelegramPost extends Model
{
    public const TYPE_BLOG_SUMMARY = 'blog_summary';
    public const TYPE_SERVICE_ADDED = 'service_added';
    public const TYPE_SERVICE_UPDATED = 'service_updated';
    public const TYPE_SERVICE_REMOVED = 'service_removed';

    public const SERVICE_TYPES = [self::TYPE_SERVICE_ADDED, self::TYPE_SERVICE_UPDATED, self::TYPE_SERVICE_REMOVED];

    // Shared between TelegramQueue's type filter and AiCosts' per-type cost breakdown, so both
    // stay in sync with each other automatically.
    public const TYPE_LABELS = [
        self::TYPE_BLOG_SUMMARY => 'Blog summary',
        self::TYPE_SERVICE_ADDED => 'New service',
        self::TYPE_SERVICE_UPDATED => 'Service updated',
        self::TYPE_SERVICE_REMOVED => 'Service removed',
    ];

    public const IMAGE_ARTICLE = 'article';
    public const IMAGE_AI_GENERATED = 'ai_generated';
    public const IMAGE_NONE = 'none';

    public const STATUS_PENDING = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    // What send-queue actually sends - confirmed is just an optional "admin looked at this"
    // marker (see TelegramSendQueueCommand), it doesn't change whether or when a post sends.
    public const SENDABLE_STATUSES = [self::STATUS_PENDING, self::STATUS_CONFIRMED];

    protected $fillable = [
        'type', 'lang', 'related_key', 'title', 'message_text', 'image_path', 'image_source',
        'scheduled_at', 'status', 'sent_at', 'error_message',
        'ai_provider', 'ai_model', 'input_tokens', 'output_tokens', 'estimated_cost_usd', 'image_cost_usd',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'sent_at' => 'datetime',
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'image_cost_usd' => 'decimal:6',
        ];
    }

    public function isDue(): bool
    {
        return $this->scheduled_at !== null && $this->scheduled_at->isPast();
    }
}
