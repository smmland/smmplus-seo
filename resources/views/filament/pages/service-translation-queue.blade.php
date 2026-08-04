<x-filament-panels::page>
    {{-- Same hand-written progress-bar CSS as blog-translation-queue.blade.php - this panel
         serves Filament's pre-built CSS bundle, which doesn't scan this app's own blade files,
         so even plain geometry utilities (h-1, w-24, left-0) can silently be missing from it. --}}
    <style>
        .st-progress-track {
            position: relative;
            height: 4px;
            width: 80px;
            overflow: hidden;
            border-radius: 9999px;
            background-color: #e5e7eb;
        }
        .dark .st-progress-track {
            background-color: rgba(255, 255, 255, .1);
        }
        .st-progress-bar {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 28px;
            border-radius: 9999px;
            background-color: rgb(var(--primary-600));
            animation: st-progress-sweep 1.1s ease-in-out infinite;
        }
        @keyframes st-progress-sweep {
            0% { transform: translateX(-28px); }
            100% { transform: translateX(80px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .st-progress-bar {
                animation: none;
                width: 100%;
                transform: none;
            }
        }

        /* Same reasoning as the progress bar above - a plain checkbox styled by hand into a
           switch, rather than reusing Filament's own Toggle field component (that one is built
           to run inside a Filament Form's field-evaluation context - $getOffColor(), a bound
           $field, etc. - and isn't meant to be dropped in standalone against a plain
           wire:model). */
        .st-toggle-track {
            position: relative;
            display: inline-block;
            width: 34px;
            height: 20px;
            flex-shrink: 0;
        }
        .st-toggle-track input {
            opacity: 0;
            width: 0;
            height: 0;
        }
        .st-toggle-slider {
            position: absolute;
            cursor: pointer;
            inset: 0;
            background-color: #d1d5db;
            transition: background-color .15s ease;
            border-radius: 9999px;
        }
        .dark .st-toggle-slider {
            background-color: rgba(255, 255, 255, .15);
        }
        .st-toggle-slider::before {
            position: absolute;
            content: "";
            height: 16px;
            width: 16px;
            left: 2px;
            top: 2px;
            background-color: white;
            transition: transform .15s ease;
            border-radius: 9999px;
        }
        .st-toggle-track input:checked + .st-toggle-slider {
            background-color: rgb(var(--primary-600));
        }
        .st-toggle-track input:checked + .st-toggle-slider::before {
            transform: translateX(14px);
        }
    </style>

    <div>
        <x-filament::section>
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-950 dark:text-white">Services catalog</p>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                        Every service lives on one shared listing page per language (unlike blog posts) - checked automatically every 12 hours, or run it now below.
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
                    Last run: synced {{ $lastSyncResult['total'] }} service(s) ({{ $lastSyncResult['new'] }} new, {{ $lastSyncResult['changed'] }} changed) ·
                    checked {{ $lastSyncResult['checked'] }}, {{ $lastSyncResult['translated'] }} confirmed translated
                    @if ($lastSyncResult['errors'] > 0)
                        · {{ $lastSyncResult['errors'] }} language fetch error(s)
                    @endif
                </p>
            @endif
        </x-filament::section>

        <x-filament::section class="mt-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search by title, category, or id…"
                    class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >

                <div class="flex flex-wrap items-center gap-3">
                    <label class="flex cursor-pointer items-center gap-2">
                        <span class="st-toggle-track">
                            <input type="checkbox" wire:model.live="hasDescriptionOnly">
                            <span class="st-toggle-slider"></span>
                        </span>
                        <span class="text-sm text-gray-600 dark:text-gray-300">Has description only</span>
                    </label>

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

            @if (! empty($selectedServices))
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        {{ count($selectedServices) }} service{{ count($selectedServices) === 1 ? '' : 's' }} selected
                    </span>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="$set('selectedServices', [])" class="text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
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
                                    Download descriptions
                                </x-filament::dropdown.list.item>

                                <x-filament::dropdown.list.item
                                    icon="heroicon-o-queue-list"
                                    wire:click="queueMissingForSelected"
                                >
                                    Queue missing descriptions
                                </x-filament::dropdown.list.item>

                                <x-filament::dropdown.list.item
                                    icon="heroicon-o-arrow-down-tray"
                                    wire:click="mountAction('downloadSelectionTitles')"
                                >
                                    Download titles
                                </x-filament::dropdown.list.item>

                                <x-filament::dropdown.list.item
                                    icon="heroicon-o-queue-list"
                                    wire:click="queueMissingTitlesForSelected"
                                >
                                    Queue missing titles
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
        @elseif ($this->queue['services']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($search !== '')
                    No services match that search.
                @elseif ($statusFilter !== 'all')
                    No services match "{{ $this::STATUS_FILTERS[$statusFilter] }}" - try switching the filter to "All services".
                @else
                    No services found yet - click "Sync now" above to fetch the catalog for the first time.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">
                                @php
                                    $selectableOnPage = collect($this->queue['services'])->reject(fn (array $s) => ! empty($s['pendingLangs']))->pluck('row.service_key');
                                @endphp
                                <input
                                    type="checkbox"
                                    wire:click="toggleSelectAllOnPage"
                                    @checked($selectableOnPage->isNotEmpty() && $selectableOnPage->diff($selectedServices)->isEmpty())
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"
                                >
                            </th>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Service (default language)</th>
                            <th class="p-2 text-start text-sm font-semibold">Translation status</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->queue['services'] as $service)
                            <tr wire:key="service-{{ $service['row']->service_key }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2">
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedServices"
                                        value="{{ $service['row']->service_key }}"
                                        @disabled(! empty($service['pendingLangs']))
                                        title="{{ ! empty($service['pendingLangs']) ? 'Already translating - nothing more to queue right now' : '' }}"
                                        @class([
                                            'rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5',
                                            'opacity-50' => ! empty($service['pendingLangs']),
                                        ])
                                    >
                                </td>
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $loop->iteration }}
                                </td>
                                <td class="p-2">
                                    <span class="font-medium text-gray-950 dark:text-white">{{ $service['row']->title ?? '(untitled)' }}</span>
                                    <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                        {{ $service['row']->category_title ?? 'Uncategorized' }} · id {{ $service['row']->service_key }}
                                    </div>
                                </td>
                                <td class="p-2">
                                    <p class="mb-1 text-xs font-medium text-gray-400 dark:text-gray-500">Description</p>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($service['languages'] as $language)
                                            @include('filament.pages.partials.service-language-badge', ['language' => $language, 'stateKey' => 'state'])
                                        @endforeach
                                    </div>

                                    @if (! empty($service['pendingLangs']))
                                        <div class="st-progress-track" style="margin-top: 6px;">
                                            <div class="st-progress-bar"></div>
                                        </div>
                                    @endif

                                    <p class="mb-1 mt-3 text-xs font-medium text-gray-400 dark:text-gray-500">Title</p>
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($service['languages'] as $language)
                                            @include('filament.pages.partials.service-language-badge', ['language' => $language, 'stateKey' => 'titleState'])
                                        @endforeach
                                    </div>

                                    @if (! empty($service['pendingTitleLangs']))
                                        <div class="st-progress-track" style="margin-top: 6px;">
                                            <div class="st-progress-bar"></div>
                                        </div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <div class="flex items-center gap-2">
                                        <x-filament::button
                                            size="sm"
                                            icon="heroicon-o-information-circle"
                                            wire:click="mountAction('viewService', {{ Illuminate\Support\Js::from(['serviceKey' => $service['row']->service_key, 'title' => $service['row']->title ?? $service['row']->service_key]) }})"
                                        >
                                            Details
                                        </x-filament::button>

                                        <x-filament::icon-button
                                            icon="heroicon-o-language"
                                            color="gray"
                                            size="sm"
                                            label="Translate missing descriptions"
                                            tooltip="Queue every missing description"
                                            wire:click="translateAllMissingForService({{ Illuminate\Support\Js::from($service['row']->service_key) }})"
                                            wire:loading.attr="disabled"
                                            wire:target="translateAllMissingForService({{ Illuminate\Support\Js::from($service['row']->service_key) }})"
                                        />

                                        <x-filament::icon-button
                                            icon="heroicon-o-tag"
                                            color="gray"
                                            size="sm"
                                            label="Translate missing titles"
                                            tooltip="Queue every missing title"
                                            wire:click="translateAllMissingTitlesForService({{ Illuminate\Support\Js::from($service['row']->service_key) }})"
                                            wire:loading.attr="disabled"
                                            wire:target="translateAllMissingTitlesForService({{ Illuminate\Support\Js::from($service['row']->service_key) }})"
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
                    {{ $this->queue['total'] }} service{{ $this->queue['total'] === 1 ? '' : 's' }}
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
