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
                                        @if ($post->image_path)
                                            <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($post->image_path) }}" alt="" class="h-12 w-12 rounded-lg object-cover ring-1 ring-gray-950/10 dark:ring-white/10">
                                        @else
                                            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-gray-100 text-gray-300 ring-1 ring-gray-950/10 dark:bg-white/5 dark:text-gray-600 dark:ring-white/10">
                                                <x-filament::icon icon="heroicon-o-photo" class="h-5 w-5" />
                                            </div>
                                        @endif
                                    </td>
                                    <td class="p-2">
                                        <x-filament::badge color="gray" size="xs">{{ $this::TYPE_FILTERS[$post->type] ?? $post->type }}</x-filament::badge>
                                        <div class="mt-1 font-medium text-gray-950 dark:text-white">{{ $post->title }}</div>
                                        <div class="mt-1 max-w-md text-xs text-gray-400 dark:text-gray-500">
                                            {{ \Illuminate\Support\Str::limit($post->message_text, 140) }}
                                        </div>
                                    </td>
                                    <td class="p-2 text-sm text-gray-600 dark:text-gray-300">
                                        {{ $post->scheduled_at->diffForHumans() }}
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
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-filament::button
                                                size="sm"
                                                color="gray"
                                                icon="heroicon-o-information-circle"
                                                wire:click="mountAction('viewPost', {{ Illuminate\Support\Js::from(['postId' => $post->id, 'title' => $post->title]) }})"
                                            >
                                                Details
                                            </x-filament::button>

                                            @if (in_array($post->status, ['pending', 'confirmed']))
                                                <x-filament::icon-button
                                                    icon="heroicon-o-pencil-square"
                                                    color="gray"
                                                    size="sm"
                                                    label="Edit"
                                                    tooltip="Edit message text"
                                                    wire:click="mountAction('editPost', {{ Illuminate\Support\Js::from(['postId' => $post->id]) }})"
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
                                                <x-filament::button
                                                    size="sm"
                                                    color="gray"
                                                    icon="heroicon-o-arrow-uturn-left"
                                                    wire:click="unrejectPost({{ $post->id }})"
                                                >
                                                    Un-reject
                                                </x-filament::button>
                                            @endif

                                            @if ($post->status === 'failed')
                                                <x-filament::button
                                                    size="sm"
                                                    color="gray"
                                                    icon="heroicon-o-arrow-path"
                                                    wire:click="retryPost({{ $post->id }})"
                                                >
                                                    Retry
                                                </x-filament::button>
                                            @endif

                                            @if ($post->status !== 'sent')
                                                <x-filament::icon-button
                                                    icon="heroicon-o-trash"
                                                    color="danger"
                                                    size="sm"
                                                    label="Delete"
                                                    tooltip="Delete"
                                                    wire:click="deletePost({{ $post->id }})"
                                                    wire:confirm="Delete this draft permanently?"
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
    </div>
</x-filament-panels::page>
