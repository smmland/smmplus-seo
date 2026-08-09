<?php

namespace App\Models;

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
}
