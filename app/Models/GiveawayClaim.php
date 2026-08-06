<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayClaim extends Model
{
    public const PLATFORM_TELEGRAM = 'telegram';

    public const PLATFORM_YOUTUBE = 'youtube';

    public const PLATFORM_LABELS = [
        self::PLATFORM_TELEGRAM => 'Telegram',
        self::PLATFORM_YOUTUBE => 'YouTube',
    ];

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_REWARDED = 'rewarded';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_VERIFIED => 'Verified - awaiting admin reward',
        self::STATUS_REWARDED => 'Rewarded',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'platform', 'panel_user_email', 'panel_username', 'platform_account_id',
        'verified_at', 'status', 'reward_note', 'rewarded_at', 'rewarded_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'verified_at' => 'datetime',
            'rewarded_at' => 'datetime',
        ];
    }

    public function rewardedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rewarded_by_user_id');
    }
}
