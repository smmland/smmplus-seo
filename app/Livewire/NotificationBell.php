<?php

namespace App\Livewire;

use App\Models\PanelNotification;
use App\Models\PanelNotificationRead;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The bell icon injected next to the account avatar (AdminPanelProvider::panel()'s
 * ->renderHook(PanelsRenderHook::USER_MENU_BEFORE, ...)). Shows the same events
 * TelegramAlertService DMs out (PanelNotificationService's call sites), but gated purely by the
 * viewing admin's own section access - never by whether Telegram DM alerts are configured, and
 * never global: read state is per-user (panel_notification_reads), since two admins with
 * different permissions shouldn't affect each other's unread badge.
 */
class NotificationBell extends Component
{
    private const RECENT_LIMIT = 30;

    #[Computed]
    public function tableReady(): bool
    {
        return Schema::hasTable('panel_notifications');
    }

    /**
     * @return list<string>
     */
    #[Computed]
    public function allowedCategories(): array
    {
        return PanelNotification::allowedCategoriesFor(auth()->user());
    }

    #[Computed]
    public function notifications()
    {
        if (! $this->tableReady() || empty($this->allowedCategories())) {
            return collect();
        }

        $userId = auth()->id();

        return PanelNotification::query()
            ->whereIn('category', $this->allowedCategories())
            ->with(['reads' => fn ($q) => $q->where('user_id', $userId)])
            ->latest()
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->each(fn (PanelNotification $notification) => $notification->setAttribute('isRead', $notification->reads->isNotEmpty()));
    }

    #[Computed]
    public function notificationsByCategory()
    {
        return $this->notifications->groupBy('category');
    }

    #[Computed]
    public function unreadCount(): int
    {
        if (! $this->tableReady() || empty($this->allowedCategories())) {
            return 0;
        }

        $userId = auth()->id();

        return PanelNotification::query()
            ->whereIn('category', $this->allowedCategories())
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->count();
    }

    public function markAsRead(int $notificationId)
    {
        $notification = PanelNotification::query()->find($notificationId);

        $this->recordRead([$notificationId]);
        unset($this->notifications, $this->notificationsByCategory, $this->unreadCount);

        if ($notification?->url) {
            return redirect($notification->url);
        }
    }

    public function markCategoryAsRead(string $category): void
    {
        $userId = auth()->id();

        $unreadIds = PanelNotification::query()
            ->where('category', $category)
            ->whereDoesntHave('reads', fn ($q) => $q->where('user_id', $userId))
            ->pluck('id')
            ->all();

        $this->recordRead($unreadIds);
        unset($this->notifications, $this->notificationsByCategory, $this->unreadCount);
    }

    /**
     * @param  list<int>  $notificationIds
     */
    private function recordRead(array $notificationIds): void
    {
        if (empty($notificationIds)) {
            return;
        }

        $userId = auth()->id();
        $now = now();

        $rows = collect($notificationIds)
            ->map(fn (int $id) => ['panel_notification_id' => $id, 'user_id' => $userId, 'read_at' => $now])
            ->all();

        PanelNotificationRead::query()->upsert($rows, ['panel_notification_id', 'user_id'], ['read_at']);
    }

    public function render()
    {
        return view('livewire.notification-bell');
    }
}
