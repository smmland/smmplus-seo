<?php

namespace App\Services;

use App\Models\PanelNotification;
use Illuminate\Support\Facades\Schema;

/**
 * Creates in-panel notification-center rows (the bell icon next to the account avatar -
 * NotificationBell). Entirely separate from TelegramAlertService's DM alerts - both are called
 * from the same event hook points (RefreshServiceCatalogCommand, AutoProcessNewBlogsCommand,
 * TelegramAlertPostPreviewsCommand, the three Process*QueueCommand's), but this one is never
 * gated by the Telegram-specific settings, only by the viewing user's own section permissions
 * (checked at read time in NotificationBell, not here).
 */
class PanelNotificationService
{
    public function notify(string $category, string $type, string $title, ?string $body = null, ?string $url = null): void
    {
        if (! Schema::hasTable('panel_notifications')) {
            return;
        }

        PanelNotification::create([
            'category' => $category,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ]);
    }
}
