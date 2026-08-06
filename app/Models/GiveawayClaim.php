<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayClaim extends Model
{
    public const PLATFORM_TELEGRAM = 'telegram';

    // YouTube is three independently-rewarded tasks, not one - each gets its own claim row (and
    // its own reward amount, see GiveawaySettingsService). The DB value for subscribe stays plain
    // 'youtube' (unchanged since the original single-task version) rather than being renamed to
    // something like 'youtube_subscribe', so nothing about already-shipped settings/claims breaks.
    public const PLATFORM_YOUTUBE_SUBSCRIBE = 'youtube';

    // Verified via a public API check (no OAuth needed - a channel's featured-channels list is
    // public data), not self-reported like Trustpilot - the user just tells us which channel is
    // theirs, we check it ourselves. See YoutubeDataApiService::checkFeaturedChannel().
    public const PLATFORM_YOUTUBE_FEATURED = 'youtube_featured';

    // Same deal - verified via a public API check (the video's own public metadata), not
    // self-reported. See YoutubeDataApiService::checkVideoProof().
    public const PLATFORM_YOUTUBE_VIDEO = 'youtube_video';

    // No public API exists to confirm a Trustpilot review is real (unlike the Telegram/YouTube
    // tasks, which all self-verify) - the user submits a link to their review as proof_url, and
    // the claim starts life as STATUS_PENDING_REVIEW rather than STATUS_VERIFIED, so the admin
    // queue can tell "we already confirmed this" apart from "someone just claims they did this".
    public const PLATFORM_TRUSTPILOT = 'trustpilot';

    public const PLATFORM_LABELS = [
        self::PLATFORM_TELEGRAM => 'Telegram',
        self::PLATFORM_YOUTUBE_SUBSCRIBE => 'YouTube - Subscribe',
        self::PLATFORM_YOUTUBE_FEATURED => 'YouTube - Featured channel',
        self::PLATFORM_YOUTUBE_VIDEO => 'YouTube - Made a video',
        self::PLATFORM_TRUSTPILOT => 'Trustpilot',
    ];

    public const STATUS_VERIFIED = 'verified';

    public const STATUS_PENDING_REVIEW = 'pending_review';

    public const STATUS_REWARDED = 'rewarded';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_LABELS = [
        self::STATUS_VERIFIED => 'Verified - awaiting admin reward',
        self::STATUS_PENDING_REVIEW => 'Self-reported - needs manual check',
        self::STATUS_REWARDED => 'Rewarded',
        self::STATUS_REJECTED => 'Rejected',
    ];

    protected $fillable = [
        'platform', 'panel_user_email', 'panel_username', 'platform_account_id', 'proof_url',
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
