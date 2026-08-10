<?php

namespace App\Models;

use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PanelNotification extends Model
{
    protected $fillable = [
        'category', 'type', 'title', 'body', 'url',
    ];

    // Category is literally a PanelSection value (see PanelSection::TELEGRAM/TRANSLATION) - a
    // notification's visibility is "does the viewing user have view access to this section",
    // computed directly from this label map's keys, not a separate mapping table.
    public const CATEGORIES = [
        'telegram' => 'Telegram',
        'translation' => 'Translation',
    ];

    public function reads(): HasMany
    {
        return $this->hasMany(PanelNotificationRead::class);
    }

    /**
     * Which categories the given user is even allowed to see notifications for - shared by
     * NotificationBell (the full dropdown) and every page-level navigation badge, so both agree
     * on visibility rather than each re-deriving it.
     *
     * @return list<string>
     */
    public static function allowedCategoriesFor(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return collect(self::CATEGORIES)
            ->keys()
            ->filter(fn (string $category) => $user->hasAccess(PanelSection::key($category, PanelSection::TIER_VIEW)))
            ->values()
            ->all();
    }
}
