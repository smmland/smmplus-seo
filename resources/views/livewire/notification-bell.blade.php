<div class="relative" x-data="{ open: false }" @click.outside="open = false" wire:poll.30s>
    @if ($this->tableReady && ! empty($this->allowedCategories))
        <button
            type="button"
            @click="open = !open"
            class="relative inline-flex items-center justify-center rounded-full"
            style="height: 2.25rem; width: 2.25rem; color: rgb(107 114 128);"
        >
            <span class="sr-only">Notifications</span>
            <x-filament::icon icon="heroicon-o-bell" style="height: 1.25rem; width: 1.25rem;" />

            @if ($this->unreadCount > 0)
                <span
                    class="absolute inline-flex items-center justify-center rounded-full text-white"
                    style="top: -2px; right: -2px; min-width: 16px; height: 16px; padding: 0 3px; font-size: 10px; line-height: 16px; background-color: rgb(var(--danger-600));"
                >
                    {{ $this->unreadCount > 99 ? '99+' : $this->unreadCount }}
                </span>
            @endif
        </button>

        <div
            x-show="open"
            x-cloak
            x-transition
            class="absolute rounded-lg bg-white shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10"
            style="top: 100%; right: 0; margin-top: 8px; width: 340px; max-width: 90vw; max-height: 420px; overflow-y: auto; z-index: 50;"
        >
            @if ($this->notifications->isEmpty())
                <p class="p-4 text-sm text-gray-500 dark:text-gray-400">No notifications yet.</p>
            @else
                @foreach ($this->notificationsByCategory as $category => $group)
                    <div class="border-t border-gray-100 dark:border-white/5">
                        <div class="flex items-center justify-between p-3" style="padding-bottom: 4px;">
                            <p class="text-xs font-semibold text-gray-500 dark:text-gray-400">
                                {{ \App\Models\PanelNotification::CATEGORIES[$category] ?? $category }}
                            </p>

                            @if ($group->contains('isRead', false))
                                <button
                                    type="button"
                                    wire:click="markCategoryAsRead('{{ $category }}')"
                                    class="text-xs font-medium text-primary-600 dark:text-primary-400"
                                >
                                    Mark as read
                                </button>
                            @endif
                        </div>

                        @foreach ($group as $notification)
                            <button
                                type="button"
                                wire:click="markAsRead({{ $notification->id }})"
                                class="block w-full p-3 text-start"
                                style="{{ $notification->isRead ? '' : 'background-color: rgba(var(--primary-500), .06);' }}"
                            >
                                <p class="text-sm font-medium text-gray-950 dark:text-white">
                                    {{ $notification->title }}
                                </p>

                                @if ($notification->body)
                                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                        {{ \Illuminate\Support\Str::limit($notification->body, 80) }}
                                    </p>
                                @endif

                                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                    {{ $notification->created_at->diffForHumans() }}
                                </p>
                            </button>
                        @endforeach
                    </div>
                @endforeach
            @endif
        </div>
    @endif
</div>
