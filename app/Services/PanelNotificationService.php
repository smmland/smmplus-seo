<?php

namespace App\Services;

use App\Models\PanelNotification;
use App\Models\PanelNotificationRead;
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

    // Backs each nav item's own badge (getNavigationBadge() on TelegramQueue,
    // Blog/Service/CategoryTranslationQueue) - every notify() call above carries the exact page
    // its event is about in $url, so a per-item count is just that same table filtered by url
    // instead of by category, same visibility rule as the bell (allowedCategoriesFor).
    public function unreadCountForUrl(string $url): int
    {
        if (! Schema::hasTable('panel_notifications') || ! auth()->check()) {
            return 0;
        }

        $allowedCategories = PanelNotification::allowedCategoriesFor(auth()->user());

        if (empty($allowedCategories)) {
            return 0;
        }

        return PanelNotification::query()
            ->where('url', $url)
            ->whereIn('category', $allowedCategories)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', auth()->id()))
            ->count();
    }

    // Called from each of those same pages' mount() - visiting the page is itself the "I've seen
    // this" signal, same as clicking a notification in the bell dropdown, so the badge and the
    // bell's unread count both clear together rather than needing a separate dismiss action.
    public function markUrlRead(string $url): void
    {
        if (! Schema::hasTable('panel_notifications') || ! auth()->check()) {
            return;
        }

        $userId = auth()->id();

        $unreadIds = PanelNotification::query()
            ->where('url', $url)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id');

        if ($unreadIds->isEmpty()) {
            return;
        }

        $now = now();

        $rows = $unreadIds
            ->map(fn (int $id) => ['panel_notification_id' => $id, 'user_id' => $userId, 'read_at' => $now])
            ->all();

        PanelNotificationRead::query()->upsert($rows, ['panel_notification_id', 'user_id'], ['read_at']);
    }
}
