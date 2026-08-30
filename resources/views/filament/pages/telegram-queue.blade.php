<x-filament-panels::page>
    <div wire:poll.30s>
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Blog summary plan</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Tops up automatically once a day to keep about a week's worth of posts scheduled ahead. Service-change announcements are drafted separately, right after each hourly services sync.
                    </p>
                    @if ($this->cronProgress['hasRun'])
                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            Last automatic run: {{ $this->cronProgress['lastRunAt']->diffForHumans() }}
                        </p>
                    @endif
                </div>

                <x-filament::button
                    icon="heroicon-o-sparkles"
                    wire:click="generatePlanNow"
                    wire:loading.attr="disabled"
                    wire:target="generatePlanNow"
                >
                    Generate now
                </x-filament::button>
            </div>
        </x-filament::section>

        <x-filament::section class="mt-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search by title or message…"
                    class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >

                <div class="flex flex-wrap items-center gap-3">
                    <select
                        wire:model.live="typeFilter"
                        class="fi-input rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >
                        @foreach ($this::TYPE_FILTERS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <select
                        wire:model.live="statusFilter"
                        class="fi-input rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                    >
                        @foreach ($this::STATUS_FILTERS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>

                    <x-filament::button
                        icon="heroicon-o-plus"
                        wire:click="mountAction('newMessage')"
                    >
                        New message
                    </x-filament::button>
                </div>
            </div>

            @if (! $this->tableReady)
                <div class="rounded-lg p-3 text-sm" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                    This feature needs a database update first - go to General Settings and click "Update database", then reload this page.
                </div>
            @elseif ($this->queue['posts']->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    @if ($search !== '' || $typeFilter !== 'all' || $statusFilter !== 'all')
                        No posts match the current search/filters.
                    @else
                        Nothing scheduled yet - click "Generate now" above, or wait for the daily/hourly automatic checks.
                    @endif
                </p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="p-2 text-start text-sm font-semibold">#</th>
                                <th class="p-2 text-start text-sm font-semibold">Image</th>
                                <th class="p-2 text-start text-sm font-semibold">Post</th>
                                <th class="p-2 text-start text-sm font-semibold">Scheduled</th>
                                <th class="p-2 text-start text-sm font-semibold">Status</th>
                                <th class="p-2 text-start text-sm font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->queue['posts'] as $post)
                                <tr wire:key="post-{{ $post->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                    <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                        {{ $loop->iteration }}
                                    </td>
                                    <td class="p-2">
                                        {{-- Explicit pixel size via inline style, not h-12/w-12 - those two aren't in
                                             Filament's precompiled CSS bundle (unlike h-6/w-6/h-5/w-5, confirmed
                                             present), so they'd silently do nothing and this would render at
                                             the source image's real dimensions instead of a small indicator. --}}
                                        @if ($post->image_path)
                                            <img src="{{ url('/telegram-images/'.$post->image_path) }}" alt="Has an image - see Details" title="Has an image - see Details" class="rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10" style="height: 96px; width: 96px;">
                                        @elseif ($post->image_generation_error ?? null)
                                            <div
                                                class="flex items-center justify-center rounded-lg ring-1 ring-gray-950/10 dark:ring-white/10"
                                                style="height: 96px; width: 96px; background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))"
                                                title="{{ 'Image generation failed: '.$post->image_generation_error }}"
                                            >
                                                <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-8 w-8" />
                                            </div>
                                        @endif
                                    </td>
                                    <td class="p-2" style="max-width: 220px;">
                                        <x-filament::badge color="gray" size="xs">{{ $this::TYPE_FILTERS[$post->type] ?? $post->type }}</x-filament::badge>
                                        <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $post->title }}</div>
                                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            {{ \Illuminate\Support\Str::limit($post->message_text, 70) }}
                                        </div>
                                    </td>
                                    <td class="p-2 text-sm text-gray-600 dark:text-gray-300" style="min-width: 150px;">
                                        {{ $post->scheduled_at->diffForHumans() }}
                                        @if (in_array($post->status, ['pending', 'confirmed']))
                                            <div style="width: 100%; max-width: 140px; height: 5px; border-radius: 9999px; overflow: hidden; background-color: rgba(148,163,184,.25); margin-top: 6px;" title="{{ $post->publishProgressPercent() }}% of the way to its scheduled time">
                                                <div style="width: {{ $post->publishProgressPercent() }}%; height: 100%; border-radius: 9999px; background-color: rgb(var(--primary-500)); transition: width .3s;"></div>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        @switch($post->status)
                                            @case('pending')
                                                <x-filament::badge color="warning" size="xs">Pending</x-filament::badge>
                                                @break
                                            @case('confirmed')
                                                <x-filament::badge color="info" size="xs">Confirmed</x-filament::badge>
                                                @break
                                            @case('rejected')
                                                <x-filament::badge color="danger" size="xs">Rejected</x-filament::badge>
                                                @break
                                            @case('sent')
                                                <x-filament::badge color="success" size="xs">Sent</x-filament::badge>
                                                @break
                                            @case('failed')
                                                <x-filament::badge color="danger" size="xs">Failed</x-filament::badge>
                                                @break
                                        @endswitch

                                        @if ($post->status === 'failed' && $post->error_message)
                                            <p class="mt-1 max-w-xs text-xs text-danger-600 dark:text-danger-400">{{ \Illuminate\Support\Str::limit($post->error_message, 100) }}</p>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        <div class="flex items-center gap-1">
                                            <x-filament::icon-button
                                                icon="heroicon-o-information-circle"
                                                color="gray"
                                                size="sm"
                                                label="Details"
                                                tooltip="Details - edit, delete, send now"
                                                wire:click="mountAction('viewPost', {{ Illuminate\Support\Js::from(['postId' => $post->id, 'title' => $post->title]) }})"
                                            />

                                            @if ($post->status === 'pending')
                                                <x-filament::icon-button
                                                    icon="heroicon-o-check"
                                                    color="success"
                                                    size="sm"
                                                    label="Confirm"
                                                    tooltip="Confirm"
                                                    wire:click="confirmPost({{ $post->id }})"
                                                />
                                            @endif

                                            @if (in_array($post->status, ['pending', 'confirmed']))
                                                <x-filament::icon-button
                                                    icon="heroicon-o-x-mark"
                                                    color="danger"
                                                    size="sm"
                                                    label="Reject"
                                                    tooltip="Reject - will not be sent"
                                                    wire:click="rejectPost({{ $post->id }})"
                                                    wire:confirm="Reject this post? It will not be sent unless you un-reject it later."
                                                />
                                            @endif

                                            @if ($post->status === 'rejected')
                                                <x-filament::icon-button
                                                    icon="heroicon-o-arrow-uturn-left"
                                                    color="gray"
                                                    size="sm"
                                                    label="Un-reject"
                                                    tooltip="Un-reject"
                                                    wire:click="unrejectPost({{ $post->id }})"
                                                />
                                            @endif

                                            @if ($post->status === 'failed')
                                                <x-filament::icon-button
                                                    icon="heroicon-o-arrow-path"
                                                    color="gray"
                                                    size="sm"
                                                    label="Retry"
                                                    tooltip="Retry"
                                                    wire:click="retryPost({{ $post->id }})"
                                                />
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-3 flex items-center justify-between gap-3">
                    <p class="text-xs text-gray-400 dark:text-gray-500">
                        {{ $this->queue['total'] }} post{{ $this->queue['total'] === 1 ? '' : 's' }}
                        @if ($this->queue['lastPage'] > 1)
                            · Page {{ $this->queue['page'] }} of {{ $this->queue['lastPage'] }}
                        @endif
                    </p>

                    @if ($this->queue['lastPage'] > 1)
                        <div class="flex items-center gap-2">
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-left"
                                :disabled="$this->queue['page'] <= 1"
                                wire:click="previousQueuePage"
                            >
                                Previous
                            </x-filament::button>
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-chevron-right"
                                icon-position="after"
                                :disabled="$this->queue['page'] >= $this->queue['lastPage']"
                                wire:click="nextQueuePage"
                            >
                                Next
                            </x-filament::button>
                        </div>
                    @endif
                </div>
            @endif
        </x-filament::section>

        <x-filament::section
            heading="Sent history (last 30)"
            description="What's actually gone out to the channel, most recent first - this is the same record fed back into the AI when writing new posts, so it can avoid repeating itself."
            collapsible
            class="mt-4"
        >
            @if (! $this->tableReady)
                <p class="text-sm text-gray-500 dark:text-gray-400">This feature needs a database update first.</p>
            @elseif ($this->sentHistory->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">Nothing has been sent yet.</p>
            @else
                <div class="overflow-x-auto">
                    <table class="fi-ta-table w-full text-start">
                        <thead>
                            <tr>
                                <th class="p-2 text-start text-sm font-semibold">Image</th>
                                <th class="p-2 text-start text-sm font-semibold">Post</th>
                                <th class="p-2 text-start text-sm font-semibold">Sent</th>
                                <th class="p-2 text-start text-sm font-semibold">Views</th>
                                <th class="p-2 text-start text-sm font-semibold">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->sentHistory as $post)
                                <tr wire:key="history-{{ $post->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                    <td class="p-2">
                                        @if ($post->image_path)
                                            <img src="{{ url('/telegram-images/'.$post->image_path) }}" alt="Has an image - see Details" title="Has an image - see Details" class="rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10" style="height: 96px; width: 96px;">
                                        @endif
                                    </td>
                                    <td class="p-2" style="max-width: 220px;">
                                        <x-filament::badge color="gray" size="xs">{{ $this::TYPE_FILTERS[$post->type] ?? $post->type }}</x-filament::badge>
                                        <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $post->title }}</div>
                                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            {{ \Illuminate\Support\Str::limit($post->message_text, 70) }}
                                        </div>
                                    </td>
                                    <td class="p-2 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $post->sent_at?->diffForHumans() }}
                                    </td>
                                    <td class="p-2 text-sm text-gray-600 dark:text-gray-300" style="min-width: 110px;">
                                        @if ($post->observed_views !== null)
                                            <div>{{ number_format($post->observed_views) }}</div>
                                            <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">checked {{ $post->views_checked_at?->diffForHumans() }}</div>
                                        @else
                                            <span class="text-gray-400">Not checked</span>
                                        @endif
                                        @if ($post->views_last_order_quantity)
                                            <div class="mt-1 text-xs text-success-600 dark:text-success-400">+{{ number_format($post->views_last_order_quantity) }} ordered</div>
                                        @endif
                                        @if ($post->views_order_error)
                                            <div class="mt-1 text-xs text-danger-600 dark:text-danger-400" title="{{ $post->views_order_error }}">{{ \Illuminate\Support\Str::limit($post->views_order_error, 55) }}</div>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        <x-filament::icon-button
                                            icon="heroicon-o-information-circle"
                                            color="gray"
                                            size="sm"
                                            label="Details"
                                            tooltip="Details - delete"
                                            wire:click="mountAction('viewPost', {{ Illuminate\Support\Js::from(['postId' => $post->id, 'title' => $post->title]) }})"
                                        />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
