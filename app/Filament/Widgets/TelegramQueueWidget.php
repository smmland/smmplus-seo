<?php

namespace App\Filament\Widgets;

use App\Filament\Pages\TelegramQueue;
use App\Models\TelegramPost;
use App\Services\TelegramSettingsService;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Schema;

/**
 * The Telegram counterpart to NewBlogTopicsWidget/ServiceTranslationWidget - what's waiting for
 * review, what's broken, how active the channel's actually been, and what's about to go out on
 * the next once-a-minute send-queue tick.
 */
class TelegramQueueWidget extends Widget
{
    protected static string $view = 'filament.widgets.telegram-queue-widget';

    // Placed after ServiceTranslationWidget (sort 4).
    protected static ?int $sort = 5;

    protected int | string | array $columnSpan = 'full';

    protected function getViewData(): array
    {
        if (! Schema::hasTable('telegram_posts')) {
            return ['ready' => false, 'queueUrl' => TelegramQueue::getUrl()];
        }

        return [
            'ready' => true,
            'enabled' => app(TelegramSettingsService::class)->isEnabled(),
            'pendingCount' => TelegramPost::query()->where('status', TelegramPost::STATUS_PENDING)->count(),
            'failedCount' => TelegramPost::query()->where('status', TelegramPost::STATUS_FAILED)->count(),
            'sentTodayCount' => TelegramPost::query()
                ->where('status', TelegramPost::STATUS_SENT)
                ->where('sent_at', '>=', now()->subDay())
                ->count(),
            'dueNowCount' => TelegramPost::query()
                ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
                ->where('scheduled_at', '<=', now())
                ->count(),
            'queueUrl' => TelegramQueue::getUrl(),
        ];
    }
}
