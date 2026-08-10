<x-filament-panels::page>
    {{-- Same hand-written progress-bar CSS as service-translation-queue.blade.php - this panel
         serves Filament's pre-built CSS bundle, which doesn't scan this app's own blade files,
         so even plain geometry utilities can silently be missing from it. --}}
    <style>
        .ct-progress-track {
            position: relative;
            height: 4px;
            width: 80px;
            overflow: hidden;
            border-radius: 9999px;
            background-color: #e5e7eb;
        }
        .dark .ct-progress-track {
            background-color: rgba(255, 255, 255, .1);
        }
        .ct-progress-bar {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 28px;
            border-radius: 9999px;
            background-color: rgb(var(--primary-600));
            animation: ct-progress-sweep 1.1s ease-in-out infinite;
        }
        @keyframes ct-progress-sweep {
            0% { transform: translateX(-28px); }
            100% { transform: translateX(80px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .ct-progress-bar {
                animation: none;
                width: 100%;
                transform: none;
            }
        }
    </style>

    <div wire:poll.20s>
        <x-filament::section>
            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                <span>Next automatic check</span>
                <span>
                    @if ($this->cronProgress['hasRun'])
                        @if ($this->cronProgress['remainingMinutes'] > 0)
                            in {{ $this->cronProgress['remainingMinutes'] }} min
                        @else
                            due any moment
                        @endif
                    @else
                        waiting for the first automatic run
                    @endif
                </span>
            </div>

            <div class="mt-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10" style="height: 8px">
                <div
                    class="rounded-full bg-primary-500 transition-all duration-500"
                    style="width: {{ $this->cronProgress['percent'] }}%; height: 8px"
                ></div>
            </div>

            @if ($this->cronProgress['hasRun'])
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Last automatic check: {{ $this->cronProgress['lastRunAt']->diffForHumans() }} - runs as part of the same hourly services catalog sync (Service Translation page); any category whose default name changed since its last translation is automatically re-translated and marked below.
                </p>
            @endif
        </x-filament::section>

        <x-filament::section class="mt-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Services catalog</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Category names come from the same services listing page - syncing here runs the exact same check as the Service Translation page's own "Sync now".
                    </p>
                </div>

                <x-filament::button
                    icon="heroicon-o-arrow-path"
                    wire:click="runSyncNow"
                    wire:loading.attr="disabled"
                    wire:target="runSyncNow"
                >
                    Sync now
                </x-filament::button>
            </div>

            @if ($lastSyncResult)
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">
                    Last run: synced the catalog and re-checked category names across active languages
                    @if ($lastSyncResult['errors'] > 0)
                        · {{ $lastSyncResult['errors'] }} language fetch error(s)
                    @endif
                </p>
            @endif
        </x-filament::section>

        <x-filament::section class="mt-4">
            <div class="mb-3 flex flex-col gap-3">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search by name or id…"
                    class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >

                <div class="flex flex-wrap items-end gap-4">
                    <div class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-gray-500 dark:text-gray-400">Status</span>
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
            </div>

            @if (! empty($selectedCategories))
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        {{ count($selectedCategories) }} categor{{ count($selectedCategories) === 1 ? 'y' : 'ies' }} selected
                    </span>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="$set('selectedCategories', [])" class="text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            Clear
                        </button>

                        <x-filament::dropdown>
                            <x-slot name="trigger">
                                <x-filament::button size="sm" icon="heroicon-o-chevron-down" icon-position="after">
                                    Actions
                                </x-filament::button>
                            </x-slot>

                            <x-filament::dropdown.list>
                                <x-filament::dropdown.list.item
                                    icon="heroicon-o-arrow-down-tray"
                                    wire:click="mountAction('downloadSelection')"
                                >
                                    Download names
                                </x-filament::dropdown.list.item>

                                <x-filament::dropdown.list.item
                                    icon="heroicon-o-queue-list"
                                    wire:click="queueMissingForSelected"
                                >
                                    Queue missing translations
                                </x-filament::dropdown.list.item>
                            </x-filament::dropdown.list>
                        </x-filament::dropdown>
                    </div>
                </div>
            @endif

        @if (! $this->databaseReady)
            <div class="rounded-lg p-3 text-sm" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                This feature needs a database update first - go to General Settings and click "Update database", then reload this page.
            </div>
        @elseif ($this->queue['categories']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($search !== '')
                    No categories match that search.
                @elseif ($statusFilter !== 'all')
                    No categories match "{{ $this::STATUS_FILTERS[$statusFilter] }}" - try switching the filter to "All categories".
                @else
                    No categories found yet - click "Sync now" above to fetch the catalog for the first time.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">
                                @php
                                    $selectableOnPage = collect($this->queue['categories'])->reject(fn (array $c) => ! empty($c['pendingLangs']))->pluck('row.category_id');
                                @endphp
                                <input
                                    type="checkbox"
                                    wire:click="toggleSelectAllOnPage"
                                    @checked($selectableOnPage->isNotEmpty() && $selectableOnPage->diff($selectedCategories)->isEmpty())
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"
                                >
                            </th>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Category (default language)</th>
                            <th class="p-2 text-start text-sm font-semibold">Translation status</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->queue['categories'] as $category)
                            <tr wire:key="category-{{ $category['row']->category_id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2">
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedCategories"
                                        value="{{ $category['row']->category_id }}"
                                        @disabled(! empty($category['pendingLangs']))
                                        title="{{ ! empty($category['pendingLangs']) ? 'Already translating - nothing more to queue right now' : '' }}"
                                        @class([
                                            'rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5',
                                            'opacity-50' => ! empty($category['pendingLangs']),
                                        ])
                                    >
                                </td>
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $loop->iteration }}
                                </td>
                                <td class="p-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $category['row']->title ?? '(untitled)' }}</span>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        id {{ $category['row']->category_id }}
                                    </div>
                                </td>
                                <td class="p-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($category['languages'] as $language)
                                            @include('filament.pages.partials.service-language-badge', ['language' => $language, 'stateKey' => 'state', 'entityKey' => $category['row']->category_id])
                                        @endforeach
                                    </div>

                                    @if (! empty($category['pendingLangs']))
                                        <div class="ct-progress-track" style="margin-top: 6px;">
                                            <div class="ct-progress-bar"></div>
                                        </div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <div class="flex items-center gap-2">
                                        <x-filament::button
                                            size="sm"
                                            icon="heroicon-o-information-circle"
                                            wire:click="mountAction('viewCategory', {{ Illuminate\Support\Js::from(['categoryId' => $category['row']->category_id, 'title' => $category['row']->title ?? $category['row']->category_id]) }})"
                                        >
                                            Details
                                        </x-filament::button>

                                        <x-filament::icon-button
                                            icon="heroicon-o-language"
                                            color="gray"
                                            size="sm"
                                            label="Translate missing languages"
                                            tooltip="Queue every missing language"
                                            wire:click="translateAllMissingForCategory({{ Illuminate\Support\Js::from($category['row']->category_id) }})"
                                            wire:loading.attr="disabled"
                                            wire:target="translateAllMissingForCategory({{ Illuminate\Support\Js::from($category['row']->category_id) }})"
                                        />
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $this->queue['total'] }} categor{{ $this->queue['total'] === 1 ? 'y' : 'ies' }}
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
