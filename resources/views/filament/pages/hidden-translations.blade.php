<x-filament-panels::page>
    <x-filament::section
        heading="Unflagged content"
        description="A page that already had a translated version live on the site before this admin ever used AI translation here - discovered normally by the sitemap sync, then only ever had 'Extract content' run on it - never gets the internal is_translated flag set (only an actual AI translation or a Recheck sets it), so it reads as missing in every list despite its content being right there. This finds and fixes those in one click - safe to run any time, it only ever touches rows that already have real extracted content."
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-gray-600 dark:text-gray-300">
                @if ($this->unflaggedContentCount > 0)
                    <strong>{{ $this->unflaggedContentCount }}</strong> row{{ $this->unflaggedContentCount === 1 ? '' : 's' }} with real content that {{ $this->unflaggedContentCount === 1 ? 'was' : 'were' }} never flagged as translated.
                @else
                    Nothing to fix - every row with extracted content is already flagged correctly.
                @endif
            </p>

            @if ($this->unflaggedContentCount > 0)
                <x-filament::button
                    size="sm"
                    color="warning"
                    icon="heroicon-o-wrench"
                    wire:click="fixUnflaggedContent"
                    wire:loading.attr="disabled"
                    wire:target="fixUnflaggedContent"
                    wire:confirm="Flag {{ $this->unflaggedContentCount }} row(s) as translated? Each one already has real extracted content - this only sets the flag so they show up correctly."
                >
                    Fix all
                </x-filament::button>
            @endif
        </div>
    </x-filament::section>

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

    <x-filament::section
        heading="Files with no database record"
        description="A translated file can exist on disk with no database row pointing at it at all - deeper than hidden or orphaned above (both still have a row to find). This happens if a topic was ever renamed after a translation was made and then deleted: the delete looked for the file under the new name and missed it, removing only the database row. Run a scan to find any of these and recover them."
    >
        <div class="mb-3">
            <x-filament::button
                size="sm"
                color="gray"
                icon="heroicon-o-magnifying-glass"
                wire:click="scanDisk"
                wire:loading.attr="disabled"
                wire:target="scanDisk"
            >
                Scan disk for content with no database record
            </x-filament::button>
        </div>

        @if ($diskScanResults === null)
            <p class="text-sm text-gray-500 dark:text-gray-400">Not run yet.</p>
        @elseif (empty($diskScanResults))
            <p class="text-sm text-gray-500 dark:text-gray-400">Nothing found - every translated file on disk has a matching database record.</p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Slug</th>
                            <th class="p-2 text-start text-sm font-semibold">Language</th>
                            <th class="p-2 text-start text-sm font-semibold">File size</th>
                            <th class="p-2 text-start text-sm font-semibold">Last modified</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($diskScanResults as $i => $result)
                            <tr wire:key="diskscan-{{ $result['slug'] }}-{{ $result['lang'] }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">{{ $i + 1 }}</td>
                                <td class="p-2 text-gray-700 dark:text-gray-200">{{ $result['slug'] }}</td>
                                <td class="p-2">
                                    <span class="inline-flex items-center rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 dark:text-gray-300 dark:ring-white/10">
                                        {{ strtoupper($result['lang']) }}
                                    </span>
                                </td>
                                <td class="p-2 text-sm text-gray-600 dark:text-gray-300">{{ number_format($result['size'] / 1024, 1) }} KB</td>
                                <td class="p-2 text-sm text-gray-600 dark:text-gray-300">{{ $result['modifiedAt']->diffForHumans() }}</td>
                                <td class="p-2">
                                    <div class="flex flex-wrap items-center gap-2">
                                        <x-filament::button
                                            size="sm"
                                            color="success"
                                            icon="heroicon-o-arrow-path"
                                            wire:click="recoverFromDisk({{ Illuminate\Support\Js::from($result['slug']) }}, {{ Illuminate\Support\Js::from($result['lang']) }})"
                                            wire:loading.attr="disabled"
                                        >
                                            Recover
                                        </x-filament::button>
                                        <x-filament::button
                                            size="sm"
                                            color="danger"
                                            outlined
                                            icon="heroicon-o-trash"
                                            wire:click="deleteDiskFile({{ Illuminate\Support\Js::from($result['slug']) }}, {{ Illuminate\Support\Js::from($result['lang']) }})"
                                            wire:confirm="Delete this file permanently? There's no database record and no way to undo this."
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

            <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                {{ count($diskScanResults) }} file{{ count($diskScanResults) === 1 ? '' : 's' }} found
            </p>
        @endif
    </x-filament::section>
</x-filament-panels::page>
