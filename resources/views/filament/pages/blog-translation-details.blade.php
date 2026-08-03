@php
    $languages = $languages instanceof \Illuminate\Support\Collection ? $languages : collect($languages);

    // Drives the "Translate all missing languages" button and its progress bar - purely derived
    // from the same per-language flags the tabs below already use, so it stays in sync with
    // nothing extra to track, refreshed the same way everything else here is (wire:poll.10s).
    $nonDefaultLanguages = $languages->where('isDefault', false);
    $totalNonDefault = $nonDefaultLanguages->count();
    $translatedCount = $nonDefaultLanguages->where('isTranslated', true)->count();
    $pendingCount = $nonDefaultLanguages->where('translationPending', true)->count();
    $missingCount = $totalNonDefault - $translatedCount - $pendingCount;

    // Shared class strings so every toolbar/panel control looks consistent without repeating a
    // huge class list on every single button.
    $tbBtn = 'inline-flex h-8 items-center justify-center gap-1 rounded-md px-2 text-sm text-gray-600 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-100 hover:text-gray-900 disabled:pointer-events-none disabled:opacity-40 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/10 dark:hover:text-white';
    $tbBtnActive = 'bg-white shadow-sm ring-1 ring-inset ring-gray-950/10 dark:bg-white/10 dark:ring-white/10';
    $tbSelect = 'fi-input h-8 rounded-md border-0 py-0 text-sm text-gray-700 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-gray-200 dark:ring-white/10';
    $panelInput = 'fi-input block w-full rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10';
    $panelLabel = 'mb-1 block text-xs font-medium text-gray-500 dark:text-gray-400';
@endphp

<div x-data="{ tab: '{{ $defaultLangCode }}' }" class="space-y-4" wire:poll.10s>
    @if ($englishRow)
        <div class="flex flex-wrap items-start gap-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <div class="mt-0.5 flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-white text-primary-600 ring-1 ring-gray-950/5 dark:bg-white/10 dark:text-primary-400 dark:ring-white/10">
                <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
            </div>
            <div class="min-w-0 flex-1 space-y-1">
                <a href="{{ $englishRow->source_url }}" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400">
                    <span class="truncate">{{ $englishRow->source_url }}</span>
                    <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5 shrink-0" />
                </a>

                @if ($englishRow->seo_title)
                    <p class="truncate text-xs text-gray-400 dark:text-gray-500">
                        <span class="font-mono">&lt;title&gt;</span> {{ $englishRow->seo_title }}
                    </p>
                @endif

                @if ($englishRow->meta_description)
                    <p class="line-clamp-2 text-sm text-gray-600 dark:text-gray-300">
                        {{ $englishRow->meta_description }}
                    </p>
                @endif
            </div>

            {{-- Lives here (not inside a specific language tab) because it operates on the whole
                 topic - whichever language is next missing, not "the language you happen to be
                 looking at" - so it stays visible no matter which tab is open. --}}
            <div class="flex shrink-0 flex-wrap items-center gap-2">
                <x-filament::button
                    size="sm"
                    color="primary"
                    icon="heroicon-o-sparkles"
                    :disabled="$missingCount === 0"
                    wire:click="translateNextMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }})"
                    wire:loading.attr="disabled"
                    wire:target="translateNextMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }}), translateAllMissingLanguages({{ Illuminate\Support\Js::from($groupKey) }})"
                >
                    Translate next missing language
                </x-filament::button>

                <x-filament::button
                    size="sm"
                    color="gray"
                    icon="heroicon-o-queue-list"
                    :disabled="$missingCount === 0"
                    wire:click="translateAllMissingLanguages({{ Illuminate\Support\Js::from($groupKey) }})"
                    wire:loading.attr="disabled"
                    wire:target="translateNextMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }}), translateAllMissingLanguages({{ Illuminate\Support\Js::from($groupKey) }})"
                >
                    Translate all missing languages
                </x-filament::button>
            </div>
        </div>

        @if ($pendingCount > 0)
            <div class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                <div class="mb-2 flex items-center justify-between gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <span class="inline-flex items-center gap-1.5">
                        <x-filament::loading-indicator class="h-4 w-4" />
                        Translating {{ $pendingCount }} language(s)… {{ $translatedCount }} of {{ $totalNonDefault }} done so far.
                    </span>
                </div>
                <div class="bt-progress-track bt-progress-track-wide"><div class="bt-progress-bar-fill" style="width: {{ $totalNonDefault > 0 ? round($translatedCount / $totalNonDefault * 100) : 0 }}%"></div></div>
            </div>
        @endif
    @endif

    <div
        x-data="{ exportOpen: false, exportSelected: {{ Illuminate\Support\Js::from($languages->mapWithKeys(fn ($l) => [$l['code'] => $l['exists'] && ! $l['isDefault']])->all()) }} }"
        class="rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10"
    >
        <button type="button" @click="exportOpen = !exportOpen" class="flex w-full items-center justify-between gap-2 text-start text-sm font-medium text-gray-950 dark:text-white">
            <span class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4 text-gray-400" />
                Download translate export
            </span>
            <x-filament::icon icon="heroicon-o-chevron-down" x-show="!exportOpen" class="h-4 w-4 text-gray-400" />
            <x-filament::icon icon="heroicon-o-chevron-up" x-show="exportOpen" x-cloak class="h-4 w-4 text-gray-400" />
        </button>

        <div x-show="exportOpen" x-cloak class="mt-3 space-y-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Pick which languages to include, then download a zip with one HTML file per language ({{ '{lang}.html' }}) plus a single <code class="rounded bg-gray-100 px-1 dark:bg-white/10">meta_seo.txt</code> listing each one's page title, meta keywords, and meta description.
            </p>

            <table class="w-full text-start text-xs">
                <thead>
                    <tr class="text-gray-500 dark:text-gray-400">
                        <th class="p-1.5 text-start font-medium"></th>
                        <th class="p-1.5 text-start font-medium">Language</th>
                        <th class="p-1.5 text-start font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($languages as $language)
                        <tr class="border-t border-gray-950/5 dark:border-white/10">
                            <td class="p-1.5">
                                <input
                                    type="checkbox"
                                    x-model="exportSelected['{{ $language['code'] }}']"
                                    {{ $language['exists'] ? '' : 'disabled' }}
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"
                                >
                            </td>
                            <td class="p-1.5 text-gray-700 dark:text-gray-200">
                                <span class="font-medium">{{ strtoupper($language['code']) }}</span>
                                <span class="text-gray-400 dark:text-gray-500">{{ $language['name'] }}</span>
                                @if ($language['isDefault'])
                                    <x-filament::badge color="gray" size="xs">Default</x-filament::badge>
                                @endif
                            </td>
                            <td class="p-1.5">
                                @if (! $language['exists'])
                                    <x-filament::badge color="gray" size="xs">No content</x-filament::badge>
                                @elseif ($language['isHidden'] ?? false)
                                    <x-filament::badge color="warning" size="xs">Hidden</x-filament::badge>
                                @elseif ($language['needsSiteUpdate'])
                                    <x-filament::badge color="warning" size="xs">Needs site update</x-filament::badge>
                                @elseif ($language['isTranslated'])
                                    <x-filament::badge color="success" size="xs">Confirmed live</x-filament::badge>
                                @else
                                    <x-filament::badge color="warning" size="xs">Not translated yet</x-filament::badge>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="flex justify-end">
                <x-filament::button
                    size="sm"
                    icon="heroicon-o-arrow-down-tray"
                    @click="$wire.downloadSeoExport({{ Illuminate\Support\Js::from($groupKey) }}, Object.keys(exportSelected).filter(code => exportSelected[code]))"
                >
                    Download zip
                </x-filament::button>
            </div>
        </div>
    </div>

    <div class="-mx-1 flex gap-1 overflow-x-auto px-1 pb-1">
        @foreach ($languages as $language)
            <button
                type="button"
                @click="tab = '{{ $language['code'] }}'"
                :class="tab === '{{ $language['code'] }}'
                    ? 'bg-primary-600 text-white shadow-sm'
                    : 'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10'"
                class="inline-flex shrink-0 items-center gap-1.5 rounded-lg px-3 py-1.5 text-sm font-medium transition"
            >
                {{ strtoupper($language['code']) }}

                {{-- Plain text-{color}-{shade} utility classes don't work here (this admin panel
                     serves Filament's pre-built CSS bundle, which never compiled these specific
                     shades since nothing else in it happens to use them - see the note on
                     blog-translation-queue.blade.php's progress bar for the fuller story on this
                     bug). Filament's own semantic colors ARE available at runtime as CSS custom
                     properties though (injected into <head> from the panel's color config), the
                     same --primary-600 the progress bar already reads - so those, not a utility
                     class, are what these icons resolve their color from. --}}
                @if ($language['isDefault'])
                    <x-filament::icon icon="heroicon-m-star" class="h-3.5 w-3.5" style="color: rgb(var(--warning-500))" />
                @elseif (! $language['exists'])
                    <x-filament::icon icon="heroicon-m-minus-circle" class="h-3.5 w-3.5 opacity-50" />
                @elseif ($language['isHidden'] ?? false)
                    <x-filament::icon icon="heroicon-m-eye-slash" class="h-3.5 w-3.5" style="color: rgb(var(--warning-600))" />
                @elseif ($language['needsSiteUpdate'])
                    <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-3.5 w-3.5" style="color: rgb(var(--warning-600))" />
                @elseif ($language['isTranslated'])
                    <x-filament::icon icon="heroicon-m-check-circle" class="h-3.5 w-3.5" style="color: rgb(var(--success-600))" />
                @else
                    <x-filament::icon icon="heroicon-m-x-circle" class="h-3.5 w-3.5" style="color: rgb(var(--warning-600))" />
                @endif
            </button>
        @endforeach
    </div>

    @foreach ($languages as $language)
        <div x-show="tab === '{{ $language['code'] }}'" x-cloak>
            @if (! $language['exists'])
                <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-gray-300 px-6 py-10 text-center dark:border-white/10">
                    <x-filament::icon icon="heroicon-o-language" class="h-8 w-8 text-gray-300 dark:text-gray-600" />
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        No {{ $language['name'] }} page exists yet for this topic.
                    </p>

                    @if (! $language['isDefault'])
                        @if ($language['translationPending'])
                            <div class="flex flex-col gap-1.5">
                                <span class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                    <x-filament::loading-indicator class="h-4 w-4" />
                                    Translating in the background… this can take a few minutes for a long article.
                                </span>
                                <div class="bt-progress-track"><div class="bt-progress-bar"></div></div>
                            </div>
                        @else
                            @if ($language['translationError'])
                                <p class="max-w-xs text-xs text-danger-600 dark:text-danger-400">
                                    Last attempt failed: {{ $language['translationError'] }}
                                </p>
                            @endif
                            <div class="flex flex-wrap items-center justify-center gap-2">
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    icon="heroicon-o-sparkles"
                                    wire:click="translateLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="translateLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }}), recheckMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                >
                                    {{ $language['translationError'] ? 'Retry translation' : 'Translate with AI' }}
                                </x-filament::button>

                                {{-- For content translated entirely outside this tool (e.g. edited directly
                                     in the site's own CMS) - checks whether it's already live at the
                                     expected address without going through AI translation at all. --}}
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    outlined
                                    icon="heroicon-o-arrow-path"
                                    wire:click="recheckMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="translateLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }}), recheckMissingLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                >
                                    Recheck
                                </x-filament::button>
                            </div>
                        @endif
                    @endif
                </div>
            @else
                <div
                    x-data="blogEditor(
                        {{ $language['urlId'] }},
                        {{ Illuminate\Support\Js::from($language['contentHtml'] ?? '') }},
                        {{ Illuminate\Support\Js::from($language['editedContent'] ?? $language['contentHtml'] ?? '') }},
                        {{ Illuminate\Support\Js::from($language['editedPreviewUrl']) }},
                        {{ Illuminate\Support\Js::from($language['editedPreviewUrlTemplate']) }},
                        {{ Illuminate\Support\Js::from(\App\Models\Language::direction($language['code'])) }}
                    )"
                    class="space-y-4"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <a
                            href="{{ $language['sourceUrl'] }}"
                            target="_blank"
                            rel="noopener"
                            class="inline-flex items-center gap-1 text-sm font-medium text-primary-600 hover:underline dark:text-primary-400"
                        >
                            Open live page
                            <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" />
                        </a>

                        <div class="flex flex-wrap items-center gap-2">
                            @if (! $language['isDefault'])
                                @if ($language['translationPending'])
                                    <span class="inline-flex items-center gap-1.5 text-sm text-gray-500 dark:text-gray-400">
                                        <x-filament::loading-indicator class="h-4 w-4" />
                                        Translating…
                                    </span>
                                    <div class="bt-progress-track"><div class="bt-progress-bar"></div></div>
                                @else
                                    @if ($language['translationError'])
                                        <span class="text-xs text-danger-600 dark:text-danger-400" title="{{ $language['translationError'] }}">
                                            Last attempt failed
                                        </span>
                                    @endif

                                    {{-- Fetches this topic's default-language page plus every one of its
                                         translated languages and compares titles, refreshing whether each
                                         one is confirmed live - not just this tab's language, but always
                                         available from here regardless of this language's current state
                                         (confirmed/needsUpdate/hidden), unlike the needsUpdate banner's own
                                         "Recheck now" further down, which only shows up once something's
                                         already flagged as needing attention. --}}
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        outlined
                                        icon="heroicon-o-arrow-path"
                                        wire:click="recheckTopic({{ Illuminate\Support\Js::from($groupKey) }})"
                                        wire:loading.attr="disabled"
                                        wire:target="recheckTopic({{ Illuminate\Support\Js::from($groupKey) }})"
                                    >
                                        Recheck
                                    </x-filament::button>

                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-o-sparkles"
                                        @click="retranslateAndRefresh({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                    >
                                        {{ $language['translationError'] ? 'Retry translation' : 'Re-translate with AI' }}
                                    </x-filament::button>

                                    <x-filament::button
                                        size="sm"
                                        color="danger"
                                        outlined
                                        icon="heroicon-o-trash"
                                        wire:click="deleteTranslation({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                        wire:confirm="Delete all {{ $language['name'] }} content for this topic? This removes the title, SEO meta, and translated page - it can't be undone, only redone by translating again."
                                    >
                                        Delete translation
                                    </x-filament::button>
                                @endif
                            @endif

                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-arrow-path"
                                @click="extractAndRefresh()"
                                x-bind:disabled="extracting"
                            >
                                <span x-text="extracting ? 'Extracting…' : {{ Illuminate\Support\Js::from($language['contentExtracted'] ? 'Re-extract content' : 'Extract content') }}"></span>
                            </x-filament::button>
                        </div>
                    </div>

                    {{-- Colored via inline styles reading Filament's own --warning-*/--success-*
                         CSS custom properties (injected into <head> at runtime from the panel's
                         color config), not bg-warning-50/text-warning-700-style utility classes -
                         this admin panel serves Filament's pre-built CSS bundle, which was never
                         compiled with those specific shades since nothing else in it happens to
                         use them (same root cause as the progress bar's --primary-600 usage). --}}
                    @if (! $language['isDefault'] && ($language['isHidden'] ?? false) && ! $language['translationPending'])
                        {{-- Never deleted - SyncService only ever flips is_active when a
                             translation's guessed URL isn't in the real sitemap (which every
                             AI-guessed URL never is, confirmed live or not - see the migration
                             that added is_ai_guessed). Reactivating also marks this row
                             is_ai_guessed so a future sync can't hide it again. --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl p-3 text-sm" style="background-color: rgba(var(--warning-500), .1)">
                            <span style="color: rgb(var(--warning-700))">
                                This translation still exists but is currently hidden - most likely by an old sitemap-sync bug that's now fixed. It was never deleted, only flagged inactive. Reactivate it to bring it back into the list and make it available for export again.
                            </span>
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-eye"
                                wire:click="reactivateLanguage({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                wire:loading.attr="disabled"
                            >
                                Reactivate
                            </x-filament::button>
                        </div>
                    @elseif (! $language['isDefault'] && $language['needsSiteUpdate'] && ! $language['translationPending'])
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl p-3 text-sm" style="background-color: rgba(var(--warning-500), .1)">
                            <span style="color: rgb(var(--warning-700))">
                                @if ($language['siteUpdateOverride'])
                                    Manually flagged as needing a site update.
                                @else
                                    Translated in this tool, but not yet confirmed live on the site{{ $language['translationCheckedAt'] ? ' - last checked '.$language['translationCheckedAt']->diffForHumans() : ' - never checked' }}{{ $language['translationCheckNote'] ? ' ('.$language['translationCheckNote'].')' : '' }}.
                                @endif
                                Copy the content onto the live site (see "Download translate export" above), then recheck.
                            </span>
                            <div class="flex shrink-0 items-center gap-2">
                                @if ($language['siteUpdateOverride'])
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        icon="heroicon-o-x-mark"
                                        wire:click="toggleSiteUpdateOverride({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                        wire:loading.attr="disabled"
                                    >
                                        Clear override
                                    </x-filament::button>
                                @endif
                                <x-filament::button
                                    size="sm"
                                    color="gray"
                                    icon="heroicon-o-arrow-path"
                                    wire:click="recheckTopic({{ Illuminate\Support\Js::from($groupKey) }})"
                                    wire:loading.attr="disabled"
                                    wire:target="recheckTopic({{ Illuminate\Support\Js::from($groupKey) }})"
                                >
                                    Recheck now
                                </x-filament::button>
                            </div>
                        </div>
                    @elseif (! $language['isDefault'] && $language['isTranslated'] && ! $language['translationPending'])
                        {{-- The automatic check can false-positive as "confirmed live" on a
                             soft-404 (a site returning HTTP 200 with some other title instead of
                             a real 404 for a page that doesn't actually exist yet) - this stays
                             visible for every confirmed language (not just flagged ones) so a
                             wrong verdict can actually be spotted and corrected by hand. --}}
                        <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl p-3 text-sm" style="background-color: rgba(var(--success-500), .1)">
                            <span style="color: rgb(var(--success-700))">
                                Confirmed live{{ $language['translationCheckedAt'] ? ' - last checked '.$language['translationCheckedAt']->diffForHumans() : '' }}{{ $language['translationTitle'] ? '. Fetched title: "'.$language['translationTitle'].'"' : '' }}
                            </span>
                            <x-filament::button
                                size="sm"
                                color="gray"
                                outlined
                                icon="heroicon-o-flag"
                                wire:click="toggleSiteUpdateOverride({{ Illuminate\Support\Js::from($groupKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                wire:loading.attr="disabled"
                                wire:confirm="This looks confirmed live, but if that's wrong (e.g. the site returns a placeholder page instead of a real 404), you can flag it back to 'needs a site update' by hand. Continue?"
                            >
                                This looks wrong
                            </x-filament::button>
                        </div>
                    @endif

                    @php
                        $copyFields = [
                            'Title' => $language['articleTitle'],
                            '<title>' => $language['seoTitle'],
                            'Meta description' => $language['metaDescription'],
                            'Meta keywords' => $language['metaKeywords'],
                            'OG title' => $language['ogTitle'],
                            'OG description' => $language['ogDescription'],
                            'Twitter title' => $language['twitterTitle'],
                            'Twitter description' => $language['twitterDescription'],
                        ];
                    @endphp

                    <dl class="divide-y divide-gray-100 overflow-hidden rounded-xl ring-1 ring-gray-950/5 dark:divide-white/5 dark:ring-white/10">
                        @foreach ($copyFields as $label => $value)
                            <div class="flex items-center justify-between gap-3 px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-white/5" x-data="{ copied: false }">
                                <div class="min-w-0 flex-1">
                                    <dt class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ $label }}</dt>
                                    <dd class="truncate text-gray-700 dark:text-gray-200" title="{{ $value }}">{{ $value ?: '—' }}</dd>
                                </div>
                                @if ($value)
                                    <button
                                        type="button"
                                        @click="navigator.clipboard.writeText({{ Illuminate\Support\Js::from($value) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                        class="shrink-0 rounded-md p-1.5 text-gray-400 transition hover:bg-gray-100 hover:text-primary-600 dark:hover:bg-white/10 dark:hover:text-primary-400"
                                    >
                                        <x-filament::icon x-show="!copied" icon="heroicon-o-clipboard-document" class="h-4 w-4" />
                                        <x-filament::icon x-show="copied" x-cloak icon="heroicon-o-clipboard-document-check" class="h-4 w-4 text-success-500" />
                                    </button>
                                @endif
                            </div>
                        @endforeach
                    </dl>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="inline-flex gap-1 rounded-lg bg-gray-100 p-1 dark:bg-white/5">
                            <button
                                type="button"
                                @click="goOriginal()"
                                :class="mode === 'original' ? '{{ $tbBtnActive }} text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
                            >
                                <x-filament::icon icon="heroicon-o-eye" class="h-4 w-4" />
                                Original
                            </button>
                            <button
                                type="button"
                                @click="goVisual()"
                                :class="mode === 'visual' ? '{{ $tbBtnActive }} text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
                            >
                                <x-filament::icon icon="heroicon-o-pencil-square" class="h-4 w-4" />
                                Visual
                            </button>
                            <button
                                type="button"
                                @click="goCode()"
                                :class="mode === 'code' ? '{{ $tbBtnActive }} text-gray-900 dark:text-white' : 'text-gray-500 dark:text-gray-400'"
                                class="inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition"
                            >
                                <x-filament::icon icon="heroicon-o-code-bracket" class="h-4 w-4" />
                                Code
                            </button>
                        </div>

                        <div x-show="mode === 'original'">
                            <button
                                type="button"
                                @click="copyOriginal()"
                                class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-primary-600 dark:hover:text-primary-400"
                            >
                                <x-filament::icon icon="heroicon-o-clipboard-document" class="h-3.5 w-3.5" />
                                <span x-text="copiedOriginal ? 'Copied!' : 'Copy original HTML'"></span>
                            </button>
                        </div>

                        <div x-show="mode !== 'original'" class="flex flex-wrap items-center gap-3">
                            <button type="button" @click="copyEdited()" class="inline-flex items-center gap-1 text-xs font-medium text-gray-400 hover:text-primary-600 dark:hover:text-primary-400">
                                <x-filament::icon icon="heroicon-o-clipboard-document" class="h-3.5 w-3.5" />
                                <span x-text="copiedEdited ? 'Copied!' : 'Copy edited HTML'"></span>
                            </button>
                            <a x-show="editedPreviewUrl" :href="editedPreviewUrl" target="_blank" rel="noopener" class="inline-flex items-center gap-1 text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                                Preview edited
                                <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-3.5 w-3.5" />
                            </a>
                            <x-filament::button size="sm" icon="heroicon-o-check" @click="save()" :disabled="false">
                                <span x-text="saving ? 'Saving…' : 'Save'"></span>
                            </x-filament::button>
                        </div>
                    </div>

                    <div x-show="mode === 'original'">
                        @if ($language['previewUrl'])
                            <iframe
                                src="{{ $language['previewUrl'] }}"
                                class="h-96 w-full rounded-xl bg-white ring-1 ring-gray-950/5 dark:ring-white/10"
                            ></iframe>
                        @else
                            <div class="flex h-40 flex-col items-center justify-center gap-2 rounded-xl border border-dashed border-gray-300 text-sm text-gray-400 dark:border-white/10 dark:text-gray-500">
                                <x-filament::icon icon="heroicon-o-photo" class="h-6 w-6 opacity-50" />
                                Content not extracted yet - click "Extract content" above.
                            </div>
                        @endif
                    </div>

                    {{-- Visual: a live, click-to-edit rendering of the content itself, with a
                         persistent formatting toolbar (select text, then format it - like any
                         normal editor) plus a floating quick-edit popup that pops up right next
                         to whatever you just selected/clicked, for the fields you need fastest. --}}
                    <div x-show="mode === 'visual'" x-cloak>
                        <p class="mb-2 text-xs text-gray-400 dark:text-gray-500">
                            Select text and use the toolbar (or the popup that appears) to format it. Click an image or link for its full options on the right.
                        </p>

                        {{-- Toolbar --}}
                        <div class="flex flex-wrap items-center gap-1.5 rounded-t-xl bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <select x-model="selFormatTag" @mousedown="restoreSelection()" @change="applyFormatBlockSelect()" class="{{ $tbSelect }}">
                                <template x-for="tag in formatTags" :key="tag">
                                    <option :value="tag" x-text="tag === 'p' ? 'Paragraph' : (tag === 'blockquote' ? 'Quote' : tag.toUpperCase())"></option>
                                </template>
                            </select>

                            <div class="inline-flex overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                                <button type="button" @mousedown.prevent="toggleBold()" title="Bold" class="inline-flex h-8 w-8 items-center justify-center font-bold text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">B</button>
                                <button type="button" @mousedown.prevent="toggleItalic()" title="Italic" class="inline-flex h-8 w-8 items-center justify-center border-s border-gray-950/10 italic text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10">I</button>
                                <button type="button" @mousedown.prevent="toggleUnderline()" title="Underline" class="inline-flex h-8 w-8 items-center justify-center border-s border-gray-950/10 underline text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10">U</button>
                            </div>

                            <select x-model="selFontFamily" @mousedown="restoreSelection()" @change="applyFontFamilySelection()" class="{{ $tbSelect }}">
                                <option value="">Default font</option>
                                <option value="font-sans">Sans-serif</option>
                                <option value="font-serif">Serif</option>
                                <option value="font-mono">Monospace</option>
                            </select>

                            <div class="inline-flex overflow-hidden rounded-md ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                                <button type="button" @mousedown.prevent="insertList(false)" title="Bullet list" class="inline-flex h-8 w-8 items-center justify-center text-gray-600 transition hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">
                                    <x-filament::icon icon="heroicon-o-list-bullet" class="h-4 w-4" />
                                </button>
                                <button type="button" @mousedown.prevent="insertList(true)" title="Numbered list" class="inline-flex h-8 w-8 items-center justify-center border-s border-gray-950/10 text-gray-600 transition hover:bg-gray-100 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/10">
                                    <x-filament::icon icon="heroicon-o-queue-list" class="h-4 w-4" />
                                </button>
                            </div>

                            <select @mousedown="restoreSelection()" @change="applyAlignToBlock($event.target.value)" class="{{ $tbSelect }}">
                                <option value="">Align…</option>
                                <option value="text-left">Left</option>
                                <option value="text-center">Center</option>
                                <option value="text-right">Right</option>
                                <option value="text-justify">Justify</option>
                            </select>

                            <select x-model="selBg" @mousedown="restoreSelection()" @change="applyHighlightSelection()" title="Highlight" class="{{ $tbSelect }}">
                                <option value="">Highlight…</option>
                                <template x-for="color in textColors" :key="color">
                                    <optgroup :label="color">
                                        <template x-for="shade in highlightShades" :key="shade">
                                            <option :value="'bg-' + color + '-' + shade" x-text="color + ' ' + shade"></option>
                                        </template>
                                    </optgroup>
                                </template>
                            </select>

                            <select x-model="selColor" @mousedown="restoreSelection()" @change="applyTextColorSelection()" title="Text color" class="{{ $tbSelect }}">
                                <option value="">Text color…</option>
                                <template x-for="color in textColors" :key="color">
                                    <optgroup :label="color">
                                        <template x-for="shade in textShades" :key="shade">
                                            <option :value="'text-' + color + '-' + shade" x-text="color + ' ' + shade"></option>
                                        </template>
                                    </optgroup>
                                </template>
                            </select>

                            <select x-model="selSize" @mousedown="restoreSelection()" @change="applyTextSizeSelection()" title="Text size" class="{{ $tbSelect }}">
                                <option value="">Size…</option>
                                <template x-for="size in textSizes" :key="size">
                                    <option :value="'text-' + size" x-text="size"></option>
                                </template>
                            </select>

                            <button type="button" @mousedown.prevent="clearFormatting()" title="Clear formatting" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-x-circle" class="h-4 w-4" />
                            </button>

                            <span class="mx-0.5 h-6 w-px bg-gray-950/10 dark:bg-white/10"></span>

                            <button type="button" @mousedown.prevent="toolbarLink()" title="Link" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-link" class="h-4 w-4" />
                            </button>

                            <label title="Insert image" class="{{ $tbBtn }} cursor-pointer">
                                <x-filament::icon icon="heroicon-o-photo" class="h-4 w-4" />
                                <input type="file" accept="image/*" class="hidden" @change="addImageFile($event)">
                            </label>

                            <button type="button" @mousedown.prevent="openVideoPopover()" title="Insert video" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-video-camera" class="h-4 w-4" />
                            </button>

                            <button type="button" @mousedown.prevent="insertReadMore()" title="Insert/move the Read more marker (hr.shorthr)" class="{{ $tbBtn }} gap-1.5">
                                <x-filament::icon icon="heroicon-o-scissors" class="h-4 w-4" />
                                Read more
                            </button>

                            <span class="mx-0.5 h-6 w-px bg-gray-950/10 dark:bg-white/10"></span>

                            <button type="button" @mousedown.prevent="undo()" x-bind:disabled="!_undoStack.length" title="Undo (Ctrl+Z)" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-arrow-uturn-left" class="h-4 w-4" />
                            </button>
                            <button type="button" @mousedown.prevent="redo()" x-bind:disabled="!_redoStack.length" title="Redo (Ctrl+Shift+Z)" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-arrow-uturn-right" class="h-4 w-4" />
                            </button>

                            <span class="flex-1"></span>

                            <button type="button" @click="goCode()" title="View code" class="{{ $tbBtn }}">
                                <x-filament::icon icon="heroicon-o-code-bracket" class="h-4 w-4" />
                            </button>
                        </div>

                        <div x-show="videoPopoverOpen" x-cloak class="flex items-center gap-2 bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <input type="text" x-model="videoUrl" placeholder="YouTube/Vimeo URL or direct video file URL" class="{{ $panelInput }} flex-1" @keydown.enter="insertVideo()">
                            <x-filament::button size="xs" @click="insertVideo()">Insert</x-filament::button>
                            <button type="button" @click="videoPopoverOpen = false" class="text-xs text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">Cancel</button>
                        </div>

                        <div class="grid grid-cols-1 gap-3 lg:grid-cols-[1fr_260px]">
                            <iframe x-ref="visualFrame" wire:ignore class="h-96 w-full rounded-b-xl bg-white ring-1 ring-gray-950/5 dark:ring-white/10"></iframe>

                            <div class="rounded-xl bg-white p-3 text-xs ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                                <template x-if="!selectedKind">
                                    <div class="flex h-full flex-col items-center justify-center gap-2 py-6 text-center text-gray-400 dark:text-gray-500">
                                        <x-filament::icon icon="heroicon-o-cursor-arrow-rays" class="h-6 w-6 opacity-50" />
                                        Click an image or link in the preview for its full options here.
                                    </div>
                                </template>

                                <template x-if="selectedKind === 'image'">
                                    <div class="space-y-3">
                                        <p class="flex items-center gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-200">
                                            <x-filament::icon icon="heroicon-o-photo" class="h-4 w-4 text-gray-400" />
                                            Image options
                                        </p>

                                        <div>
                                            <label class="{{ $panelLabel }}">Alt text</label>
                                            <input type="text" x-model="imgAlt" @input="applyImageAlt()" class="{{ $panelInput }}">
                                        </div>

                                        <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                                            <input type="checkbox" x-model="imgRounded" @change="toggleImageRounded()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5">
                                            Rounded corners
                                        </label>

                                        <label class="flex cursor-pointer items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-center text-gray-600 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5">
                                            <x-filament::icon icon="heroicon-o-arrow-path" class="h-3.5 w-3.5" />
                                            <span x-text="uploadingImage ? 'Uploading…' : 'Replace image'"></span>
                                            <input type="file" accept="image/*" class="hidden" @change="replaceImageFile($event)">
                                        </label>

                                        <button type="button" @click="removeImage()" class="flex w-full items-center justify-center gap-1.5 rounded-lg px-2 py-1.5 text-danger-600 ring-1 ring-inset ring-danger-600/20 transition hover:bg-danger-50 dark:text-danger-400 dark:ring-danger-500/20 dark:hover:bg-danger-500/10">
                                            <x-filament::icon icon="heroicon-o-trash" class="h-3.5 w-3.5" />
                                            Remove image
                                        </button>
                                    </div>
                                </template>

                                <template x-if="selectedKind === 'link'">
                                    <div class="space-y-3">
                                        <p class="flex items-center gap-1.5 text-sm font-semibold text-gray-700 dark:text-gray-200">
                                            <x-filament::icon icon="heroicon-o-link" class="h-4 w-4 text-gray-400" />
                                            Link options
                                        </p>

                                        <div>
                                            <label class="{{ $panelLabel }}">URL</label>
                                            <input type="text" x-model="linkHref" @change="applyLink()" class="{{ $panelInput }}">
                                        </div>

                                        <div>
                                            <label class="{{ $panelLabel }}">Link text</label>
                                            <input type="text" x-model="linkText" @change="applyLink()" class="{{ $panelInput }}">
                                        </div>

                                        <div>
                                            <label class="{{ $panelLabel }}">Title attribute (tooltip)</label>
                                            <input type="text" x-model="linkTitle" @change="applyLink()" class="{{ $panelInput }}">
                                        </div>

                                        <div>
                                            <label class="{{ $panelLabel }}">Open in</label>
                                            <select x-model="linkTarget" @change="applyLink()" class="{{ $panelInput }}">
                                                <option value="_self">Same tab</option>
                                                <option value="_blank">New tab</option>
                                            </select>
                                        </div>

                                        <div class="space-y-1.5 rounded-lg bg-gray-50 p-2 dark:bg-white/5">
                                            <p class="text-xs font-medium uppercase tracking-wide text-gray-400 dark:text-gray-500">SEO / rel attributes</p>
                                            <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" x-model="linkNofollow" @change="applyLink()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"> nofollow</label>
                                            <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" x-model="linkSponsored" @change="applyLink()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"> sponsored</label>
                                            <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" x-model="linkUgc" @change="applyLink()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"> ugc</label>
                                            <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" x-model="linkNoopener" @change="applyLink()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"> noopener</label>
                                            <label class="flex items-center gap-1.5 text-gray-600 dark:text-gray-300"><input type="checkbox" x-model="linkNoreferrer" @change="applyLink()" class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"> noreferrer</label>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>

                        {{-- Floating quick-edit popup - appears right next to a text selection
                             (bold/color/size/tag) or a clicked image (alt) / link (url), so the
                             fastest edits don't need a trip to the toolbar or the panel above. --}}
                        <div
                            x-show="popupVisible"
                            x-cloak
                            :style="`position: fixed; top: ${popupTop}px; left: ${popupLeft}px; z-index: 60;`"
                            class="flex items-center gap-1 rounded-xl bg-white p-1.5 text-sm shadow-xl ring-1 ring-gray-950/10 dark:bg-gray-800 dark:ring-white/10"
                        >
                            <template x-if="popupKind === 'text'">
                                <div class="flex items-center gap-1">
                                    <button type="button" @mousedown.prevent="toggleBold()" title="Bold" class="inline-flex h-8 w-8 items-center justify-center rounded-md font-bold text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-white/10">B</button>
                                    <select x-model="selFormatTag" @mousedown="restoreSelection()" @change="applyFormatBlockSelect()" class="{{ $tbSelect }}">
                                        <template x-for="tag in formatTags" :key="tag">
                                            <option :value="tag" x-text="tag === 'p' ? 'P' : (tag === 'blockquote' ? 'Quote' : tag.toUpperCase())"></option>
                                        </template>
                                    </select>
                                    <select x-model="selColor" @mousedown="restoreSelection()" @change="applyTextColorSelection()" class="{{ $tbSelect }} w-20">
                                        <option value="">Color</option>
                                        <template x-for="color in textColors" :key="color">
                                            <optgroup :label="color">
                                                <template x-for="shade in textShades" :key="shade">
                                                    <option :value="'text-' + color + '-' + shade" x-text="color + ' ' + shade"></option>
                                                </template>
                                            </optgroup>
                                        </template>
                                    </select>
                                    <select x-model="selSize" @mousedown="restoreSelection()" @change="applyTextSizeSelection()" class="{{ $tbSelect }} w-16">
                                        <option value="">Size</option>
                                        <template x-for="size in textSizes" :key="size">
                                            <option :value="'text-' + size" x-text="size"></option>
                                        </template>
                                    </select>
                                </div>
                            </template>

                            <template x-if="popupKind === 'link'">
                                <input type="text" x-model="linkHref" @change="applyLink()" placeholder="URL" class="{{ $panelInput }} w-56">
                            </template>

                            <template x-if="popupKind === 'image'">
                                <input type="text" x-model="imgAlt" @input="applyImageAlt()" placeholder="Alt text" class="{{ $panelInput }} w-56">
                            </template>
                        </div>

                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
                            <label class="inline-flex cursor-pointer items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-plus" class="h-3.5 w-3.5" />
                                <span x-text="uploadingImage ? 'Uploading…' : 'Add image'"></span>
                                <input type="file" accept="image/*" class="hidden" @change="addImageFile($event)">
                            </label>

                            <p class="text-xs text-gray-400 dark:text-gray-500" x-show="!editedPreviewUrl">
                                Edit above, then Save to create an edited preview link.
                            </p>
                        </div>
                    </div>

                    <div x-show="mode === 'code'" x-cloak class="space-y-3">
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" @click="scanImages()" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-photo" class="h-3.5 w-3.5" />
                                Fix image ALT text
                            </button>
                            <button type="button" @click="scanLinks()" class="inline-flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 transition hover:bg-gray-50 dark:text-gray-300 dark:ring-white/10 dark:hover:bg-white/5">
                                <x-filament::icon icon="heroicon-o-link" class="h-3.5 w-3.5" />
                                Fix links
                            </button>
                        </div>

                        <div x-show="showImages" x-cloak class="max-h-48 space-y-2 overflow-y-auto rounded-xl bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <template x-if="images.length === 0">
                                <p class="text-xs text-gray-400">No images found in this content.</p>
                            </template>
                            <template x-for="(img, i) in images" :key="i">
                                <div class="flex items-center gap-2">
                                    <img :src="img.src" class="h-8 w-8 shrink-0 rounded-md object-cover ring-1 ring-gray-950/10 dark:ring-white/10" onerror="this.style.visibility='hidden'">
                                    <input type="text" x-model="img.alt" placeholder="Alt text..." class="{{ $panelInput }} flex-1">
                                </div>
                            </template>
                            <x-filament::button size="xs" color="gray" @click="applyImages()" x-show="images.length > 0">
                                Apply to content
                            </x-filament::button>
                        </div>

                        {{--
                            Each switch below is driven entirely by Alpine (:class for the track
                            color, :style for the thumb's transform) rather than a CSS
                            peer-checked selector - this admin panel ships Filament's pre-built
                            CSS bundle (no app-specific Tailwind build step scans these blade
                            files), so any peer-checked:*/translate-x-4 utility invented here
                            would compile to nothing and silently never apply, in both themes.
                            :class/:style merge with the static classes/style Alpine finds
                            already on the element, so the base geometry below stays as plain
                            (verified-present) utility classes.
                        --}}
                        <div x-show="showLinks" x-cloak class="max-h-56 overflow-auto rounded-xl bg-gray-50 p-2 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                            <template x-if="links.length === 0">
                                <p class="text-xs text-gray-400">No links found in this content.</p>
                            </template>
                            <template x-if="links.length > 0">
                                <table class="w-full text-start text-xs">
                                    <thead>
                                        <tr class="text-gray-500 dark:text-gray-400">
                                            <th class="p-1.5 text-start font-medium">Link</th>
                                            <th class="p-1.5 text-start font-medium">URL</th>
                                            <th class="p-1.5 text-center font-medium">nofollow</th>
                                            <th class="p-1.5 text-center font-medium">sponsored</th>
                                            <th class="p-1.5 text-center font-medium">ugc</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <template x-for="(link, i) in links" :key="i">
                                            <tr class="border-t border-gray-950/5 dark:border-white/10">
                                                <td class="max-w-40 truncate p-1.5 text-gray-500 dark:text-gray-400" x-text="link.text"></td>
                                                <td class="p-1.5">
                                                    <input type="text" x-model="link.href" class="{{ $panelInput }} w-full">
                                                </td>
                                                <td class="p-1.5 text-center">
                                                    <label class="relative inline-flex h-5 w-9 cursor-pointer items-center">
                                                        <input type="checkbox" x-model="link.nofollow" class="sr-only">
                                                        <span class="absolute inset-0 rounded-full transition-colors" :class="link.nofollow ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10'"></span>
                                                        <span class="pointer-events-none absolute h-4 w-4 rounded-full bg-white shadow" :style="'top:2px; left:2px; transition: transform .15s ease-in-out; transform: translateX(' + (link.nofollow ? '1rem' : '0') + ')'"></span>
                                                    </label>
                                                </td>
                                                <td class="p-1.5 text-center">
                                                    <label class="relative inline-flex h-5 w-9 cursor-pointer items-center">
                                                        <input type="checkbox" x-model="link.sponsored" class="sr-only">
                                                        <span class="absolute inset-0 rounded-full transition-colors" :class="link.sponsored ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10'"></span>
                                                        <span class="pointer-events-none absolute h-4 w-4 rounded-full bg-white shadow" :style="'top:2px; left:2px; transition: transform .15s ease-in-out; transform: translateX(' + (link.sponsored ? '1rem' : '0') + ')'"></span>
                                                    </label>
                                                </td>
                                                <td class="p-1.5 text-center">
                                                    <label class="relative inline-flex h-5 w-9 cursor-pointer items-center">
                                                        <input type="checkbox" x-model="link.ugc" class="sr-only">
                                                        <span class="absolute inset-0 rounded-full transition-colors" :class="link.ugc ? 'bg-primary-600' : 'bg-gray-200 dark:bg-white/10'"></span>
                                                        <span class="pointer-events-none absolute h-4 w-4 rounded-full bg-white shadow" :style="'top:2px; left:2px; transition: transform .15s ease-in-out; transform: translateX(' + (link.ugc ? '1rem' : '0') + ')'"></span>
                                                    </label>
                                                </td>
                                            </tr>
                                        </template>
                                    </tbody>
                                </table>
                            </template>
                            <x-filament::button size="xs" color="gray" @click="applyLinks()" x-show="links.length > 0" class="mt-2">
                                Apply to content
                            </x-filament::button>
                        </div>

                        {{-- x-ref stays on the outer div (JS needs it to find the mount point);
                             x-ignore goes on the inner one CodeMirror actually renders into, so
                             Alpine's mutation observer never walks its rapidly-churning internal
                             DOM (new line/cursor/measurement nodes on every keystroke) - without
                             it, Alpine ends up interleaved with CodeMirror's render cycle often
                             enough to desync its internal line-measurement cache from the DOM,
                             throwing "Cannot read properties of undefined (reading 'map')" from
                             deep inside codemirror.js on every keystroke.

                             The @click handler below works around a separate issue: inside this
                             modal (which uses Alpine's x-trap focus trap), clicking into
                             CodeMirror's own scroller never actually focuses its hidden textarea
                             once the document is tall enough to scroll - CodeMirror's own
                             mousedown-driven focus+cursor-placement silently fails to stick, so
                             the editor renders normally but every keystroke is a no-op. Driving
                             focus and cursor placement ourselves from the click coordinates
                             (confirmed reliable even though the trap fights CodeMirror elsewhere)
                             makes typing actually work. --}}
                        <div x-ref="codeContainer" wire:ignore @click="if (codeMirror) { codeMirror.setCursor(codeMirror.coordsChar({ left: $event.clientX, top: $event.clientY }, 'window')); codeMirror.focus(); }" class="h-72 w-full overflow-auto rounded-xl text-xs ring-1 ring-gray-950/5 dark:ring-white/10">
                            <div x-ignore class="h-full w-full"></div>
                        </div>

                        <p class="text-xs text-gray-400 dark:text-gray-500" x-show="!editedPreviewUrl">
                            Edit the HTML above, then Save to create an edited preview link.
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
