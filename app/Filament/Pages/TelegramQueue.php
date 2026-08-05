<?php

namespace App\Filament\Pages;

use App\Models\TelegramPost;
use App\Services\TelegramPostGeneratorService;
use App\Services\TelegramSettingsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;

/**
 * The review queue for every AI-drafted Telegram post - both the weekly blog-summary plan and
 * the service-change announcements, one shared list since the admin reviews both the same way
 * (see TelegramPostGeneratorService). Confirm/Reject don't control *whether* a post is generated
 * (that already happened) - only whether it actually sends: reject is a hard stop, everything
 * else sends at its scheduled_at regardless of being explicitly confirmed or just left alone
 * (TelegramSendQueueCommand).
 */
class TelegramQueue extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-paper-airplane';

    protected static ?string $navigationGroup = 'Telegram Channel';

    protected static ?string $navigationLabel = 'Queue';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.telegram-queue';

    public string $search = '';

    public string $typeFilter = 'all';

    public string $statusFilter = 'all';

    public int $queuePage = 1;

    private const QUEUE_PER_PAGE = 20;

    // How many past sent posts sentHistory() shows - a quick-glance record of what's actually
    // gone out, separate from the review queue above (which mixes past/future/rejected together
    // under the "all statuses" filter, sorted soonest-scheduled-first rather than most-recent).
    private const SENT_HISTORY_LIMIT = 30;

    public const TYPE_FILTERS = ['all' => 'All types', ...TelegramPost::TYPE_LABELS];

    public const STATUS_FILTERS = [
        'all' => 'All statuses',
        TelegramPost::STATUS_PENDING => 'Pending (will send at its scheduled time)',
        TelegramPost::STATUS_CONFIRMED => 'Confirmed',
        TelegramPost::STATUS_REJECTED => 'Rejected',
        TelegramPost::STATUS_SENT => 'Sent',
        TelegramPost::STATUS_FAILED => 'Failed',
    ];

    public function updatedSearch(): void
    {
        $this->queuePage = 1;
    }

    public function updatedTypeFilter(): void
    {
        $this->queuePage = 1;
    }

    public function updatedStatusFilter(): void
    {
        $this->queuePage = 1;
    }

    public function previousQueuePage(): void
    {
        $this->queuePage = max(1, $this->queuePage - 1);
    }

    public function nextQueuePage(): void
    {
        $this->queuePage++;
    }

    #[Computed]
    public function tableReady(): bool
    {
        return Schema::hasTable('telegram_posts');
    }

    #[Computed]
    public function cronProgress(): array
    {
        $settings = app(TelegramSettingsService::class);
        $lastRunAt = $settings->getLastWeeklyPlanRunAt();

        if (! $lastRunAt) {
            return ['hasRun' => false, 'lastRunAt' => null];
        }

        return ['hasRun' => true, 'lastRunAt' => $lastRunAt];
    }

    /**
     * @return array{posts: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function queue()
    {
        if (! $this->tableReady()) {
            return ['posts' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0];
        }

        $query = TelegramPost::query();

        if ($this->typeFilter !== 'all') {
            $query->where('type', $this->typeFilter);
        }

        if ($this->statusFilter !== 'all') {
            $query->where('status', $this->statusFilter);
        }

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                    ->orWhere('message_text', 'like', "%{$this->search}%")
                    ->orWhere('related_key', 'like', "%{$this->search}%");
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::QUEUE_PER_PAGE));
        $page = max(1, min($this->queuePage, $lastPage));

        $posts = $query
            ->orderBy('scheduled_at')
            ->skip(($page - 1) * self::QUEUE_PER_PAGE)
            ->take(self::QUEUE_PER_PAGE)
            ->get();

        return ['posts' => $posts, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    /**
     * The most recently sent posts, newest first - a plain, always-up-to-date record of what's
     * actually gone out to the channel (TelegramPost rows are never auto-pruned, so this is just
     * a read of that permanent history, same data recentMessagesContext() feeds back into new
     * prompts - see TelegramPostGeneratorService).
     */
    #[Computed]
    public function sentHistory()
    {
        if (! $this->tableReady()) {
            return collect();
        }

        return TelegramPost::query()
            ->where('status', TelegramPost::STATUS_SENT)
            ->orderByDesc('sent_at')
            ->limit(self::SENT_HISTORY_LIMIT)
            ->get();
    }

    /**
     * Immediate, manual counterpart to the daily telegram:generate-weekly-plan schedule -
     * service-change announcements have no equivalent button here since they're only ever
     * drafted as a side effect of the Service Translation page's own "Sync now"/hourly sync.
     */
    public function generatePlanNow(TelegramPostGeneratorService $generator): void
    {
        if (! app(TelegramSettingsService::class)->isEnabled()) {
            Notification::make()
                ->title('Telegram integration is disabled')
                ->body('Enable it on the Settings page first.')
                ->warning()
                ->send();

            return;
        }

        $result = $generator->topUpBlogPlan();

        unset($this->queue);

        if ($result['created'] === 0) {
            Notification::make()
                ->title('Nothing new to schedule')
                ->body($result['message'] ?? 'The week ahead is already fully scheduled.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("Created {$result['created']} new draft(s)")
            ->success()
            ->send();
    }

    public function confirmPost(int $postId): void
    {
        TelegramPost::query()->where('id', $postId)->where('status', TelegramPost::STATUS_PENDING)->update(['status' => TelegramPost::STATUS_CONFIRMED]);

        unset($this->queue);
    }

    public function rejectPost(int $postId): void
    {
        TelegramPost::query()
            ->where('id', $postId)
            ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
            ->update(['status' => TelegramPost::STATUS_REJECTED]);

        unset($this->queue);

        Notification::make()->title('Post rejected - it will not be sent')->success()->send();
    }

    // Un-rejects a post, putting it back into normal review - status returns to pending rather
    // than confirmed, since un-rejecting isn't the same as an admin actively re-approving it.
    public function unrejectPost(int $postId): void
    {
        TelegramPost::query()->where('id', $postId)->where('status', TelegramPost::STATUS_REJECTED)->update(['status' => TelegramPost::STATUS_PENDING]);

        unset($this->queue);
    }

    // Gives a failed send another chance - due immediately rather than waiting on whatever its
    // original scheduled_at was (long past by the time it's noticed failed).
    public function retryPost(int $postId): void
    {
        TelegramPost::query()->where('id', $postId)->where('status', TelegramPost::STATUS_FAILED)->update([
            'status' => TelegramPost::STATUS_PENDING,
            'scheduled_at' => now(),
            'error_message' => null,
        ]);

        unset($this->queue);

        Notification::make()->title('Queued for retry - it will send on the next check')->success()->send();
    }

    public function deletePost(int $postId): void
    {
        TelegramPost::query()->where('id', $postId)->delete();

        unset($this->queue);
        unset($this->sentHistory);
    }

    public function editPostAction(): Action
    {
        return Action::make('editPost')
            ->label('Edit')
            ->icon('heroicon-o-pencil-square')
            ->color('gray')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->fillForm(function (array $arguments): array {
                $post = TelegramPost::query()->find($arguments['postId']);

                return ['messageText' => $post?->message_text];
            })
            ->form([
                Textarea::make('messageText')
                    ->label('Message text')
                    ->required()
                    ->rows(8),
            ])
            ->action(function (array $data, array $arguments) {
                TelegramPost::query()->where('id', $arguments['postId'])->update(['message_text' => $data['messageText']]);

                unset($this->queue);

                Notification::make()->title('Post updated')->success()->send();
            });
    }

    public function viewPostAction(): Action
    {
        return Action::make('viewPost')
            ->label('Details')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->modalHeading(fn (array $arguments) => $arguments['title'] ?? 'Post details')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view(
                'filament.pages.telegram-post-details',
                ['post' => TelegramPost::query()->find($arguments['postId'])],
            ));
    }
}
