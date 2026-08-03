<x-filament-panels::page>
    <x-filament::section
        heading="Hidden translations"
        description="Every translation SyncService has hidden (is_active flagged false) because its guessed URL wasn't in the real sitemap - never deleted, just not shown in the normal list. Reactivate one at a time, or all at once."
    >
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <input
                type="text"
                wire:model.live.debounce.400ms="search"
                placeholder="Search by slug, title, or URL…"
                class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
            >

            @if ($this->hiddenTranslations['total'] > 0)
                <x-filament::button
                    size="sm"
                    color="warning"
                    icon="heroicon-o-eye"
                    wire:click="reactivateAll"
                    wire:loading.attr="disabled"
                    wire:confirm="Reactivate all {{ $this->hiddenTranslations['total'] }} hidden translation(s)? They'll become visible again and protected from being hidden by a future sitemap sync."
                >
                    Reactivate all
                </x-filament::button>
            @endif
        </div>

        @if ($this->hiddenTranslations['items']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($search !== '')
                    No hidden translations match that search.
                @else
                    Nothing is currently hidden - every translation is showing normally.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Topic</th>
                            <th class="p-2 text-start text-sm font-semibold">Language</th>
                            <th class="p-2 text-start text-sm font-semibold">Translated / last checked</th>
                            <th class="p-2 text-start text-sm font-semibold">Status before being hidden</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->hiddenTranslations['items'] as $i => $item)
                            @php
                                $row = $item['row'];
                                $english = $item['englishRow'];
                            @endphp
                            <tr wire:key="hidden-{{ $row->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ ($this->hiddenTranslations['page'] - 1) * 20 + $i + 1 }}
                                </td>
                                <td class="p-2">
                                    @if ($english)
                                        <a href="{{ $english->source_url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                            {{ $english->article_title ?: $english->slug }}
                                        </a>
                                    @else
                                        <span class="text-gray-500 dark:text-gray-400">{{ $row->slug }}</span>
                                    @endif
                                    @if ($row->article_title)
                                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $row->article_title }}</p>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 dark:text-gray-300 dark:ring-white/10">
                                        {{ strtoupper($row->lang) }}
                                        <x-filament::icon icon="heroicon-m-eye-slash" class="h-3 w-3" style="color: rgb(var(--warning-600))" />
                                    </span>
                                </td>
                                <td class="p-2 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->translation_checked_at)
                                        {{ $row->translation_checked_at->diffForHumans() }}
                                        <p class="text-xs text-gray-400 dark:text-gray-500">last live check</p>
                                    @else
                                        {{ $row->last_seen_at?->diffForHumans() ?? '—' }}
                                        <p class="text-xs text-gray-400 dark:text-gray-500">translated, never live-checked</p>
                                    @endif
                                </td>
                                <td class="p-2">
                                    @if ($row->translation_checked_at === null || $row->translation_title === null)
                                        <x-filament::badge color="gray" size="xs">Never confirmed</x-filament::badge>
                                    @elseif ($item['needsSiteUpdate'])
                                        <x-filament::badge color="warning" size="xs">Needed a site update</x-filament::badge>
                                    @else
                                        <x-filament::badge color="success" size="xs">Was confirmed live</x-filament::badge>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-o-eye"
                                        wire:click="reactivate({{ $row->id }})"
                                        wire:loading.attr="disabled"
                                    >
                                        Reactivate
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $this->hiddenTranslations['total'] }} hidden translation{{ $this->hiddenTranslations['total'] === 1 ? '' : 's' }}
                    @if ($this->hiddenTranslations['lastPage'] > 1)
                        · Page {{ $this->hiddenTranslations['page'] }} of {{ $this->hiddenTranslations['lastPage'] }}
                    @endif
                </p>

                @if ($this->hiddenTranslations['lastPage'] > 1)
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-left"
                            :disabled="$this->hiddenTranslations['page'] <= 1"
                            wire:click="previousPage"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-right"
                            icon-position="after"
                            :disabled="$this->hiddenTranslations['page'] >= $this->hiddenTranslations['lastPage']"
                            wire:click="nextPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>

    <x-filament::section
        heading="Orphaned translations"
        description="Translated pages whose original article no longer matches any currently-active topic - almost always because that article was renamed or removed on the live site after this translation was made. Also never deleted, but there's no topic left here to attach them back to automatically - open the preview below, copy what you need, then delete the old one from here once you're done with it."
    >
        <div class="mb-3">
            <input
                type="text"
                wire:model.live.debounce.400ms="orphanSearch"
                placeholder="Search by old slug, title, or URL…"
                class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
            >
        </div>

        @if ($this->orphanedTranslations['items']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($orphanSearch !== '')
                    No orphaned translations match that search.
                @else
                    None found - every translation still has a matching active topic.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Old slug / translated title</th>
                            <th class="p-2 text-start text-sm font-semibold">Language</th>
                            <th class="p-2 text-start text-sm font-semibold">Translated / last checked</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->orphanedTranslations['items'] as $i => $item)
                            @php $row = $item['row']; @endphp
                            <tr wire:key="orphan-{{ $row->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ ($this->orphanedTranslations['page'] - 1) * 20 + $i + 1 }}
                                </td>
                                <td class="p-2">
                                    <a href="{{ $row->source_url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $row->slug }}
                                    </a>
                                    @if ($row->article_title)
                                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">{{ $row->article_title }}</p>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 dark:text-gray-300 dark:ring-white/10">
                                        {{ strtoupper($row->lang) }}
                                    </span>
                                </td>
                                <td class="p-2 text-sm text-gray-600 dark:text-gray-300">
                                    @if ($row->translation_checked_at)
                                        {{ $row->translation_checked_at->diffForHumans() }}
                                    @else
                                        {{ $row->last_seen_at?->diffForHumans() ?? '—' }}
                                    @endif
                                </td>
                                <td class="p-2">
                                    <div class="flex flex-wrap items-center gap-3">
                                        @if ($item['previewUrl'])
                                            <a
                                                href="{{ $item['previewUrl'] }}"
                                                target="_blank"
                                                rel="noopener"
                                                class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                                            >
                                                Open preview
                                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                                            </a>
                                        @else
                                            <span class="text-xs text-gray-400 dark:text-gray-500">No preview extracted</span>
                                        @endif

                                        <x-filament::button
                                            size="sm"
                                            color="danger"
                                            outlined
                                            icon="heroicon-o-trash"
                                            wire:click="deleteOrphaned({{ $row->id }})"
                                            wire:confirm="Delete this orphaned {{ strtoupper($row->lang) }} translation? Only do this once you've copied anything you still needed from it - it can't be undone."
                                        >
                                            Delete
                                        </x-filament::button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $this->orphanedTranslations['total'] }} orphaned translation{{ $this->orphanedTranslations['total'] === 1 ? '' : 's' }}
                    @if ($this->orphanedTranslations['lastPage'] > 1)
                        · Page {{ $this->orphanedTranslations['page'] }} of {{ $this->orphanedTranslations['lastPage'] }}
                    @endif
                </p>

                @if ($this->orphanedTranslations['lastPage'] > 1)
                    <div class="flex items-center gap-2">
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-left"
                            :disabled="$this->orphanedTranslations['page'] <= 1"
                            wire:click="previousOrphanPage"
                        >
                            Previous
                        </x-filament::button>
                        <x-filament::button
                            size="sm"
                            color="gray"
                            icon="heroicon-o-chevron-right"
                            icon-position="after"
                            :disabled="$this->orphanedTranslations['page'] >= $this->orphanedTranslations['lastPage']"
                            wire:click="nextOrphanPage"
                        >
                            Next
                        </x-filament::button>
                    </div>
                @endif
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
