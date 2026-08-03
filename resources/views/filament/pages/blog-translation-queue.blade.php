<x-filament-panels::page>
    {{-- Indeterminate progress bar for anything translating in the background - plain hand-
         written CSS (not Tailwind utility classes) for every property, including geometry that
         would normally just be a couple of utility classes: this admin panel serves Filament's
         pre-built CSS bundle (see blog-translation-details.blade.php's Fix-links switches for
         the full story), and this time even plain, unrelated-to-that-bug classes like h-1,
         w-24, and left-0 turned out to be missing from it too - so nothing here is left to
         chance. Defined once on this real page load so it also applies inside the topic details
         modal, whose content gets injected into the DOM dynamically. Bar color reads the panel's
         live accent color (General Settings > Appearance) via Filament's own --primary-600
         variable, the same one bg-primary-600 resolves to. --}}
    <style>
        .bt-progress-track {
            position: relative;
            height: 4px;
            width: 80px;
            overflow: hidden;
            border-radius: 9999px;
            background-color: #e5e7eb;
        }
        .dark .bt-progress-track {
            background-color: rgba(255, 255, 255, .1);
        }
        .bt-progress-bar {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            width: 28px;
            border-radius: 9999px;
            background-color: rgb(var(--primary-600));
            animation: bt-progress-sweep 1.1s ease-in-out infinite;
        }
        @keyframes bt-progress-sweep {
            0% { transform: translateX(-28px); }
            100% { transform: translateX(80px); }
        }
        @media (prefers-reduced-motion: reduce) {
            .bt-progress-bar {
                animation: none;
                width: 100%;
                transform: none;
            }
        }

        /* Determinate variant (a real known percentage - how many of a topic's languages are
           translated so far) - unlike .bt-progress-bar above, this has no animation/transform of
           its own, so a percentage set via an inline width style renders exactly as given instead
           of fighting the sweep keyframes, which assume a fixed 28px bar. */
        .bt-progress-track-wide {
            width: 100%;
        }
        .bt-progress-bar-fill {
            position: absolute;
            top: 0;
            bottom: 0;
            left: 0;
            border-radius: 9999px;
            background-color: rgb(var(--primary-600));
            transition: width .3s ease;
        }
    </style>

    <div wire:poll.20s>
        <x-filament::section>
            <div class="flex items-center justify-between text-sm text-gray-600 dark:text-gray-300">
                <span>Next automatic check (up to 40 URLs)</span>
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

            <div class="mt-2 h-2 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                <div
                    class="h-full rounded-full bg-primary-500 transition-all duration-500"
                    style="width: {{ $this->cronProgress['percent'] }}%"
                ></div>
            </div>

            @if ($this->cronProgress['hasRun'])
                <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                    Last automatic check: {{ $this->cronProgress['lastRunAt']->diffForHumans() }}
                </p>
            @endif
        </x-filament::section>

        {{-- Nothing SyncService does ever calls delete() on a translated row - it only ever
             flips is_active when a guessed translation URL isn't in the real sitemap (see
             SyncService's is_ai_guessed exemption). This banner exists to answer "did those
             disappear or are they just hidden" directly, and to make recovering them a single
             click instead of a mystery. --}}
        @if ($this->hiddenTranslationsCount > 0)
            <x-filament::section>
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex items-start gap-2 text-sm text-gray-600 dark:text-gray-300">
                        <x-filament::icon icon="heroicon-o-eye-slash" class="mt-0.5 h-5 w-5 shrink-0" style="color: rgb(var(--warning-600))" />
                        <span>
                            <strong>{{ $this->hiddenTranslationsCount }}</strong> translation{{ $this->hiddenTranslationsCount === 1 ? '' : 's' }} across your topics {{ $this->hiddenTranslationsCount === 1 ? 'is' : 'are' }} currently hidden - most likely by a sitemap-sync bug that's now fixed. Nothing was deleted, they were only flagged inactive. Use the "Has a hidden translation" filter above to review them one at a time, or reactivate everything at once now.
                        </span>
                    </div>
                    <x-filament::button
                        size="sm"
                        color="warning"
                        icon="heroicon-o-eye"
                        wire:click="reactivateAllHiddenTranslations"
                        wire:loading.attr="disabled"
                        wire:confirm="Reactivate all {{ $this->hiddenTranslationsCount }} hidden translation(s)? They'll become visible again and protected from being hidden by a future sitemap sync."
                    >
                        Reactivate all
                    </x-filament::button>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section>
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <input
                    type="text"
                    wire:model.live.debounce.400ms="search"
                    placeholder="Search by slug, title, or URL…"
                    class="fi-input block w-full max-w-sm rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >
                <select
                    wire:model.live="statusFilter"
                    class="fi-input rounded-lg border-0 py-1.5 text-sm text-gray-950 ring-1 ring-inset ring-gray-950/10 focus:ring-2 focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10"
                >
                    @foreach ($this::STATUS_FILTERS as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            @if (! empty($selectedTopics))
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gray-50 p-3 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
                    <span class="text-sm text-gray-600 dark:text-gray-300">
                        {{ count($selectedTopics) }} topic{{ count($selectedTopics) === 1 ? '' : 's' }} selected
                    </span>
                    <div class="flex items-center gap-2">
                        <button type="button" wire:click="$set('selectedTopics', [])" class="text-xs font-medium text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                            Clear
                        </button>
                        <x-filament::button
                            size="sm"
                            icon="heroicon-o-queue-list"
                            wire:click="queueMissingForSelectedTopics"
                            wire:loading.attr="disabled"
                            wire:target="queueMissingForSelectedTopics"
                        >
                            Queue missing translations
                        </x-filament::button>
                    </div>
                </div>
            @endif

        @if ($this->queue['topics']->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                @if ($search !== '')
                    No topics match that search.
                @elseif ($statusFilter !== 'all')
                    No topics match "{{ $this::STATUS_FILTERS[$statusFilter] }}" - try switching the filter to "All topics".
                @else
                    No blog topics found yet.
                @endif
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">
                                @php
                                    $selectableOnPage = collect($this->queue['topics'])->reject(fn (array $t) => ! empty($t['pendingLangs']))->pluck('url.group_key');
                                @endphp
                                <input
                                    type="checkbox"
                                    wire:click="toggleSelectAllOnPage"
                                    @checked($selectableOnPage->isNotEmpty() && $selectableOnPage->diff($selectedTopics)->isEmpty())
                                    class="rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5"
                                >
                            </th>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Topic (default language)</th>
                            <th class="p-2 text-start text-sm font-semibold">Translation status</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->queue['topics'] as $topic)
                            <tr wire:key="topic-{{ $topic['url']->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2">
                                    <input
                                        type="checkbox"
                                        wire:model.live="selectedTopics"
                                        value="{{ $topic['url']->group_key }}"
                                        @disabled(! empty($topic['pendingLangs']))
                                        title="{{ ! empty($topic['pendingLangs']) ? 'Already translating - nothing more to queue right now' : '' }}"
                                        @class([
                                            'rounded border-gray-300 text-primary-600 focus:ring-primary-600 dark:border-white/20 dark:bg-white/5',
                                            'opacity-50' => ! empty($topic['pendingLangs']),
                                        ])
                                    >
                                </td>
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $loop->iteration }}
                                </td>
                                <td class="p-2">
                                    <a href="{{ $topic['url']->source_url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $topic['url']->slug }}
                                    </a>

                                    {{-- Extracting the default-language content is a prerequisite for AI
                                         translation (BlogAiTranslationService refuses to run without it), so
                                         this is worth surfacing here rather than only inside the topic popup. --}}
                                    <div class="mt-1">
                                        @if ($topic['url']->content_extracted_at)
                                            <span class="inline-flex items-center gap-1 text-xs text-gray-400 dark:text-gray-500">
                                                <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" style="color: rgb(var(--success-600))" />
                                                Content extracted {{ $topic['url']->content_extracted_at->diffForHumans() }}
                                            </span>
                                        @else
                                            <span class="inline-flex items-center gap-1 text-xs" style="color: rgb(var(--warning-600))">
                                                <x-filament::icon icon="heroicon-m-exclamation-circle" class="h-3 w-3" />
                                                Content not extracted yet
                                            </span>
                                        @endif
                                    </div>
                                </td>
                                <td class="p-2">
                                    {{-- One badge per active language, colored/iconed by state - same visual
                                         language as the tab pills in the topic details popup (see that view's
                                         comment for why plain text-{color}-{shade} utilities aren't used here). --}}
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($topic['languages'] as $language)
                                            <span
                                                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-gray-600 ring-1 ring-inset ring-gray-950/10 dark:text-gray-300 dark:ring-white/10"
                                                title="{{ $language['name'] }}: {{ match ($language['state']) {
                                                    'default' => 'Default language',
                                                    'pending' => 'Translating…',
                                                    'missing' => 'Not translated',
                                                    'needsUpdate' => 'Translated - needs a site update',
                                                    'confirmed' => 'Confirmed live',
                                                    'hidden' => 'Hidden - was translated, now hidden (probably by a sitemap sync) - open Details to reactivate it',
                                                } }}"
                                            >
                                                {{ strtoupper($language['code']) }}
                                                @switch($language['state'])
                                                    @case('default')
                                                        <x-filament::icon icon="heroicon-m-star" class="h-3 w-3" style="color: rgb(var(--warning-500))" />
                                                        @break
                                                    @case('pending')
                                                        <x-filament::loading-indicator class="h-3 w-3" />
                                                        @break
                                                    @case('needsUpdate')
                                                        <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-3 w-3" style="color: rgb(var(--warning-600))" />
                                                        @break
                                                    @case('confirmed')
                                                        <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" style="color: rgb(var(--success-600))" />
                                                        @break
                                                    @case('hidden')
                                                        <x-filament::icon icon="heroicon-m-eye-slash" class="h-3 w-3" style="color: rgb(var(--warning-600))" />
                                                        @break
                                                    @default
                                                        <x-filament::icon icon="heroicon-m-x-circle" class="h-3 w-3 opacity-50" />
                                                @endswitch
                                            </span>
                                        @endforeach
                                    </div>

                                    @if (! empty($topic['pendingLangs']))
                                        <div class="bt-progress-track" style="margin-top: 6px;">
                                            <div class="bt-progress-bar"></div>
                                        </div>
                                    @endif
                                </td>
                                <td class="p-2">
                                    <div class="flex items-center gap-2">
                                        <x-filament::button
                                            size="sm"
                                            icon="heroicon-o-information-circle"
                                            wire:click="mountAction('viewTopic', {{ Illuminate\Support\Js::from(['groupKey' => $topic['url']->group_key, 'title' => $topic['url']->article_title ?? $topic['url']->slug]) }})"
                                        >
                                            Details
                                        </x-filament::button>

                                        <div class="flex items-center gap-0.5 rounded-lg p-0.5 ring-1 ring-inset ring-gray-950/10 dark:ring-white/10">
                                            <x-filament::icon-button
                                                icon="heroicon-o-arrow-path"
                                                color="gray"
                                                size="sm"
                                                label="Recheck"
                                                tooltip="Recheck this topic"
                                                wire:click="recheckTopic({{ Illuminate\Support\Js::from($topic['url']->group_key) }})"
                                                wire:loading.attr="disabled"
                                                wire:target="recheckTopic({{ Illuminate\Support\Js::from($topic['url']->group_key) }})"
                                            />
                                            <x-filament::icon-button
                                                icon="heroicon-o-photo"
                                                color="gray"
                                                size="sm"
                                                label="Extract content"
                                                tooltip="Extract content"
                                                wire:click="extractContent({{ $topic['url']->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="extractContent({{ $topic['url']->id }})"
                                            />
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-3 flex items-center justify-between gap-3">
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    {{ $this->queue['total'] }} topic{{ $this->queue['total'] === 1 ? '' : 's' }}
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

    {{-- Code-editor mode uses CodeMirror, vendored under resources/vendor-js/codemirror and
         streamed through the /editor-assets/ route (app/Http/Controllers/EditorAssetController)
         rather than served as a plain static file under public/ - this host's static-file
         serving has already proven unreliable for new paths added there (both public/vendor and
         public/js-libs 404'd in production despite deploying correctly; see the note on
         BlogContentAssetController for why storage:link isn't relied on either), so this follows
         the same "stream it through a real Laravel route" pattern that's already proven to work
         here instead. --}}
    <link rel="stylesheet" href="{{ url('/editor-assets/codemirror/codemirror.css') }}">
    <script src="{{ url('/editor-assets/codemirror/codemirror.js') }}"></script>
    <script src="{{ url('/editor-assets/codemirror/mode/xml/xml.js') }}"></script>
    <script src="{{ url('/editor-assets/codemirror/mode/javascript/javascript.js') }}"></script>
    <script src="{{ url('/editor-assets/codemirror/mode/css/css.js') }}"></script>
    <script src="{{ url('/editor-assets/codemirror/mode/htmlmixed/htmlmixed.js') }}"></script>

    {{-- Defined here (not in the modal's own view) because Filament's action modal content is
         injected into the DOM dynamically (Livewire morph), and browsers never execute <script>
         tags inserted that way - only ones present in a real page load, like this one. --}}
    <script>
        const BLOG_EDITOR_TEXT_COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'indigo', 'purple'];
        const BLOG_EDITOR_TEXT_SHADES = ['400', '500', '600', '700', '900'];
        const BLOG_EDITOR_HIGHLIGHT_SHADES = ['100', '200', '300'];
        const BLOG_EDITOR_TEXT_SIZES = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'];
        const BLOG_EDITOR_COLOR_CLASS_RE = /^text-(gray|red|orange|yellow|green|blue|indigo|purple)-(50|100|200|300|400|500|600|700|800|900)$/;
        const BLOG_EDITOR_SIZE_CLASS_RE = /^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl)$/;
        const BLOG_EDITOR_BG_CLASS_RE = /^bg-(gray|red|orange|yellow|green|blue|indigo|purple)-(50|100|200|300|400|500|600|700|800|900)$/;
        const BLOG_EDITOR_FONT_FAMILY_RE = /^font-(sans|serif|mono)$/;
        const BLOG_EDITOR_ALIGN_RE = /^text-(left|center|right|justify)$/;
        const BLOG_EDITOR_FORMAT_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'blockquote'];

        function blogEditor(urlId, originalHtml, editedHtml, editedPreviewUrl, editedPreviewUrlTemplate, dir) {
            return {
                urlId: urlId,
                mode: 'original',
                dir: dir || 'ltr',
                // Reactive (not a closure variable) so extractAndRefresh()/retranslateAndRefresh()
                // can update it after the content changes underneath an already-mounted editor -
                // a Livewire re-render alone won't do it (morphs preserve already-mounted x-data
                // instead of re-running it with the fresh server-computed constructor arguments).
                originalHtml: originalHtml || '',
                html: editedHtml || originalHtml || '',
                editedPreviewUrl: editedPreviewUrl || null,
                saving: false,
                extracting: false,
                copiedOriginal: false,
                copiedEdited: false,

                // Code mode - bulk find/replace tools, operate on the raw html string.
                showImages: false,
                showLinks: false,
                images: [],
                links: [],
                codeMirror: null,

                // Visual mode - click-to-select inspector (images/links - full options).
                selectedKind: null,
                _selectedNode: null,
                selColor: '',
                selSize: '',
                imgAlt: '',
                imgRounded: false,
                linkHref: '',
                linkText: '',
                linkTitle: '',
                linkTarget: '_self',
                linkNofollow: false,
                linkSponsored: false,
                linkUgc: false,
                linkNoopener: false,
                linkNoreferrer: false,
                uploadingImage: false,

                // Visual mode - persistent toolbar state.
                selFontFamily: '',
                selBg: '',
                selFormatTag: 'p',
                _savedRange: null,

                // Visual mode - floating quick-edit popup (appears right after selecting text,
                // or clicking an image/link) so common edits don't require reaching the toolbar
                // or the side panel.
                popupVisible: false,
                popupKind: null,
                popupTop: 0,
                popupLeft: 0,

                // Visual mode - video insert popover.
                videoPopoverOpen: false,
                videoUrl: '',

                // Visual mode - undo/redo history (a custom stack of html snapshots, not the
                // browser's native contenteditable undo - that history gets left inconsistent by
                // the programmatic span wrap/unwrap this editor does for color/size/etc, so
                // native Ctrl+Z is disabled inside the iframe in favor of this).
                _undoStack: [],
                _redoStack: [],
                _typingSnapshotPending: false,
                _typingTimer: null,

                textColors: BLOG_EDITOR_TEXT_COLORS,
                textShades: BLOG_EDITOR_TEXT_SHADES,
                highlightShades: BLOG_EDITOR_HIGHLIGHT_SHADES,
                textSizes: BLOG_EDITOR_TEXT_SIZES,
                formatTags: BLOG_EDITOR_FORMAT_TAGS,

                copyOriginal() {
                    navigator.clipboard.writeText(this.originalHtml || '');
                    this.copiedOriginal = true;
                    setTimeout(() => { this.copiedOriginal = false; }, 1500);
                },
                extractAndRefresh() {
                    this.extracting = true;
                    this.$wire.call('extractContent', this.urlId).then((result) => {
                        this.extracting = false;
                        if (!result || !result.ok) return;
                        // Only auto-adopt the fresh content into the working copy if it hadn't
                        // diverged from the old original yet - if the user already has their own
                        // edits in progress (html !== originalHtml), re-extracting the source
                        // must not silently overwrite unsaved work.
                        if (this.html === this.originalHtml) {
                            this.html = result.contentHtml;
                            if (this.mode === 'code' && this.codeMirror) this.codeMirror.setValue(this.html);
                            if (this.mode === 'visual') this.renderVisual();
                        }
                        this.originalHtml = result.contentHtml;
                    });
                },
                // Translation now runs on the background queue (routes/console.php) rather than
                // inline in this request, so this call only queues it - it doesn't come back
                // with the finished content to hot-swap in. The "Translating…" badge (server-
                // rendered from BlogTranslationJob's status, refreshed by this modal's own
                // wire:poll) is what tells the admin it's done; closing and reopening this topic
                // afterwards picks up the fresh translation, same as reopening it any other time.
                retranslateAndRefresh(groupKey, langCode) {
                    this.$wire.call('translateLanguage', groupKey, langCode);
                },
                copyEdited() {
                    navigator.clipboard.writeText(this.html || '');
                    this.copiedEdited = true;
                    setTimeout(() => { this.copiedEdited = false; }, 1500);
                },
                scanImages() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    this.images = [...doc.querySelectorAll('img')].map((img, i) => ({
                        index: i,
                        src: img.getAttribute('src') || '',
                        alt: img.getAttribute('alt') || '',
                    }));
                    this.showImages = true;
                    this.showLinks = false;
                },
                applyImages() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    const imgs = doc.querySelectorAll('img');
                    this.images.forEach((item) => {
                        if (imgs[item.index]) {
                            imgs[item.index].setAttribute('alt', item.alt);
                        }
                    });
                    this.html = doc.body.innerHTML;
                    // This tool lives inside Code mode, alongside the CodeMirror instance itself -
                    // without pushing the result into it, the editor keeps showing the pre-edit
                    // text, and typing into it afterwards would overwrite `html` right back with
                    // that stale value on the next keystroke.
                    if (this.codeMirror) this.codeMirror.setValue(this.html);
                    this.showImages = false;
                },
                scanLinks() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    this.links = [...doc.querySelectorAll('a[href]')].map((a, i) => {
                        const relTokens = (a.getAttribute('rel') || '').split(/\s+/).filter(Boolean);
                        return {
                            index: i,
                            href: a.getAttribute('href') || '',
                            text: (a.textContent || '').trim().slice(0, 40),
                            nofollow: relTokens.includes('nofollow'),
                            sponsored: relTokens.includes('sponsored'),
                            ugc: relTokens.includes('ugc'),
                            // Any other rel tokens (author, license, noopener, ...) are preserved
                            // as-is so applying these three SEO checkboxes doesn't clobber them.
                            otherRel: relTokens.filter((t) => !['nofollow', 'sponsored', 'ugc'].includes(t)),
                        };
                    });
                    this.showLinks = true;
                    this.showImages = false;
                },
                applyLinks() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    const links = doc.querySelectorAll('a[href]');
                    this.links.forEach((item) => {
                        if (links[item.index]) {
                            links[item.index].setAttribute('href', item.href);
                            const rel = [
                                ...item.otherRel,
                                ...(item.nofollow ? ['nofollow'] : []),
                                ...(item.sponsored ? ['sponsored'] : []),
                                ...(item.ugc ? ['ugc'] : []),
                            ];
                            if (rel.length) {
                                links[item.index].setAttribute('rel', rel.join(' '));
                            } else {
                                links[item.index].removeAttribute('rel');
                            }
                        }
                    });
                    this.html = doc.body.innerHTML;
                    if (this.codeMirror) this.codeMirror.setValue(this.html);
                    this.showLinks = false;
                },

                // ---- mode switching - each mode holds its own live copy of the content, so
                // switching away from one syncs it back into `html` before the next mode reads it.
                goOriginal() {
                    if (this.mode === 'visual') this.syncFromVisual();
                    if (this.mode === 'code') this.syncFromCode();
                    this.hidePopup();
                    this.mode = 'original';
                },
                goVisual() {
                    if (this.mode === 'code') this.syncFromCode();
                    this.mode = 'visual';
                    this.$nextTick(() => this.renderVisual());
                },
                goCode() {
                    if (this.mode === 'visual') this.syncFromVisual();
                    this.hidePopup();
                    this.mode = 'code';
                    this.$nextTick(() => this.initOrRefreshCode());
                },

                // ---- visual mode: a contenteditable iframe (Tailwind CDN, so every utility
                // class renders exactly like the live site), a persistent formatting toolbar
                // driven by real text selections, plus click-to-select image/link inspectors.
                renderVisual() {
                    const frame = this.$refs.visualFrame;
                    if (!frame) return;
                    this.clearSelection();
                    this.hidePopup();
                    // A fresh srcdoc load replaces the iframe's document entirely, so any range
                    // saved from the previous document would point at now-detached nodes.
                    this._savedRange = null;
                    // Built with split closing tags ("<" + "/head>" etc.) because Livewire's
                    // asset injector does a raw string search across the whole rendered response
                    // for the literal head/body closing tags and splices its own script/style
                    // tags in wherever it finds them - including inside this JS string literal,
                    // which corrupted it into broken, unparseable JS when written as normal text.
                    const doc = '<!doctype html><html dir="' + this.dir + '"><head><meta charset="utf-8">'
                        + '<script src="https://cdn.tailwindcss.com"><' + '/script>'
                        + '<style>body{padding:1.5rem;max-width:48rem;margin:0 auto;outline:none;}'
                        + '[data-blogeditor-selected]{outline:2px solid #6366f1;outline-offset:2px;}</style>'
                        + '<' + '/head><body contenteditable="true" dir="' + this.dir + '">' + this.html + '<' + '/body><' + '/html>';
                    frame.onload = () => {
                        const idoc = frame.contentDocument;
                        // Snapshot for undo before the first keystroke of a burst of typing (not
                        // every keystroke - that would make one Ctrl+Z undo a single character).
                        // A pause of 800ms starts a new burst/undo step.
                        idoc.body.addEventListener('beforeinput', () => {
                            if (!this._typingSnapshotPending) {
                                this.pushUndoSnapshot();
                                this._typingSnapshotPending = true;
                            }
                            clearTimeout(this._typingTimer);
                            this._typingTimer = setTimeout(() => { this._typingSnapshotPending = false; }, 800);
                        });
                        idoc.body.addEventListener('input', () => this.syncFromVisual());
                        idoc.body.addEventListener('click', (e) => this.visualClick(e));
                        idoc.addEventListener('selectionchange', () => this.onSelectionChange());
                        // The browser's own contenteditable undo history gets left inconsistent
                        // by this editor's programmatic DOM changes (wrapping/unwrapping spans
                        // for color/size/etc bypasses it entirely), so native Ctrl+Z is replaced
                        // with the custom html-snapshot stack instead.
                        idoc.addEventListener('keydown', (e) => {
                            const mod = e.ctrlKey || e.metaKey;
                            if (!mod) return;
                            const key = e.key.toLowerCase();
                            if (key === 'z' && !e.shiftKey) { e.preventDefault(); this.undo(); }
                            else if (key === 'y' || (key === 'z' && e.shiftKey)) { e.preventDefault(); this.redo(); }
                        });
                    };
                    frame.srcdoc = doc;
                },
                syncFromVisual() {
                    const frame = this.$refs.visualFrame;
                    if (frame && frame.contentDocument && frame.contentDocument.body) {
                        // Read from a clone so the selection highlight marker (needed live, for
                        // the outline around the clicked element) never ends up saved into html.
                        const clone = frame.contentDocument.body.cloneNode(true);
                        clone.querySelectorAll('[data-blogeditor-selected]').forEach((n) => n.removeAttribute('data-blogeditor-selected'));
                        this.html = clone.innerHTML;
                    }
                },
                // ---- undo/redo: a stack of html snapshots, taken right before a change is
                // made (see pushUndoSnapshot() call sites throughout this file's mutating
                // actions), so undo/redo work the same in Visual mode regardless of whether the
                // change came from typing, the toolbar, the popup, or the side panel.
                pushUndoSnapshot() {
                    this._undoStack.push(this.html);
                    if (this._undoStack.length > 50) this._undoStack.shift();
                    this._redoStack = [];
                },
                undo() {
                    if (!this._undoStack.length) return;
                    this._redoStack.push(this.html);
                    this.html = this._undoStack.pop();
                    this.applyHtmlToVisual();
                },
                redo() {
                    if (!this._redoStack.length) return;
                    this._undoStack.push(this.html);
                    this.html = this._redoStack.pop();
                    this.applyHtmlToVisual();
                },
                applyHtmlToVisual() {
                    const frame = this.$refs.visualFrame;
                    if (frame && frame.contentDocument && frame.contentDocument.body) {
                        frame.contentDocument.body.innerHTML = this.html;
                    }
                    this.clearSelection();
                    this.hidePopup();
                },
                clearHighlight() {
                    const frame = this.$refs.visualFrame;
                    if (frame && frame.contentDocument) {
                        frame.contentDocument.querySelectorAll('[data-blogeditor-selected]').forEach((n) => n.removeAttribute('data-blogeditor-selected'));
                    }
                },
                clearSelection() {
                    this.clearHighlight();
                    this.selectedKind = null;
                    this._selectedNode = null;
                },
                hidePopup() {
                    this.popupVisible = false;
                    this.popupKind = null;
                },
                visualClick(e) {
                    const frame = this.$refs.visualFrame;
                    const idoc = frame.contentDocument;
                    const body = idoc.body;

                    if (e.target === body) {
                        // A mouse drag-selection also fires its final "click" on the body (the
                        // browser reports the release there), which would otherwise immediately
                        // hide the popup onSelectionChange() just showed for that same
                        // selection - a real selection made with the mouse never got a chance
                        // to stay open, while one made by keyboard (shift+arrows, no click
                        // event at all) worked fine. Only treat this as "clicked empty space to
                        // deselect" when there's no selection left standing.
                        const sel = idoc.getSelection();
                        if (sel && sel.rangeCount && !sel.isCollapsed) return;
                        this.clearSelection();
                        this.hidePopup();
                        return;
                    }
                    if (e.target.tagName === 'IMG') { this.selectImage(e.target); return; }

                    const link = e.target.closest ? e.target.closest('a') : null;
                    if (link) { this.selectLink(link); return; }

                    // Plain text click (no selection made) - nothing to format yet, just make
                    // sure any stale image/link inspector state and popup are cleared.
                    this.clearSelection();
                    this.hidePopup();
                },
                // ---- selection tracking: text formatting (color/size/bold/...) always acts on
                // the current real text selection, the same as any normal rich-text editor - you
                // select text first, then format it. The selection lives inside the iframe's own
                // document, and clicking a toolbar/popup control in the parent document would
                // normally collapse it, so the last real (non-collapsed) selection is kept saved
                // and restored into the iframe right before every formatting action runs.
                onSelectionChange() {
                    const frame = this.$refs.visualFrame;
                    if (!frame || !frame.contentDocument) return;
                    const idoc = frame.contentDocument;
                    const sel = idoc.getSelection();
                    if (!sel || !sel.rangeCount) return;
                    // Always track the cursor/selection (even collapsed - a plain click just
                    // places the cursor) so cursor-only actions like alignment, format-block,
                    // and inserting a list/image/video/Read-more marker land in the right spot.
                    // The floating quick popup, though, only makes sense for a real selection.
                    this._savedRange = sel.getRangeAt(0).cloneRange();
                    if (!sel.isCollapsed) {
                        this.showTextPopup(sel.getRangeAt(0), sel);
                    } else if (this.popupKind === 'text') {
                        this.hidePopup();
                    }
                },
                restoreSelection() {
                    const frame = this.$refs.visualFrame;
                    if (!frame || !frame.contentDocument || !this._savedRange) return;
                    const idoc = frame.contentDocument;
                    idoc.body.focus();
                    const sel = idoc.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(this._savedRange);
                },
                showTextPopup(range, sel) {
                    const frame = this.$refs.visualFrame;
                    const frameRect = frame.getBoundingClientRect();
                    const rect = range.getBoundingClientRect();
                    if (!rect || (!rect.width && !rect.height)) return;
                    this.popupKind = 'text';
                    this.popupTop = Math.max(8, frameRect.top + rect.top - 48);
                    this.popupLeft = frameRect.left + rect.left;
                    this.popupVisible = true;

                    let node = sel.anchorNode;
                    if (node && node.nodeType === 3) node = node.parentElement;
                    const classes = node && node.getAttribute ? (node.getAttribute('class') || '').split(/\s+/) : [];
                    this.selColor = classes.find((c) => BLOG_EDITOR_COLOR_CLASS_RE.test(c)) || '';
                    this.selSize = classes.find((c) => BLOG_EDITOR_SIZE_CLASS_RE.test(c)) || '';
                    const block = node && node.closest ? node.closest('p, h1, h2, h3, h4, h5, h6, blockquote, li') : null;
                    this.selFormatTag = block ? block.tagName.toLowerCase() : 'p';
                },
                showElementPopup(kind, el) {
                    const frame = this.$refs.visualFrame;
                    const frameRect = frame.getBoundingClientRect();
                    const rect = el.getBoundingClientRect();
                    this.popupKind = kind;
                    this.popupTop = Math.max(8, frameRect.top + rect.top - 48);
                    this.popupLeft = frameRect.left + rect.left;
                    this.popupVisible = true;
                },
                // ---- Tailwind-class-based formatting, applied to the current text selection
                // (wraps/rewraps the selected range in a <span>, replacing any existing class
                // from the same family) - keeps every formatting option a real Tailwind utility
                // class instead of an inline style="", matching how the rest of this content is
                // already built.
                applyClassToSelection(regex, newClass, skipSnapshot) {
                    this.restoreSelection();
                    const frame = this.$refs.visualFrame;
                    const idoc = frame.contentDocument;
                    const sel = idoc.getSelection();
                    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;
                    if (!skipSnapshot) this.pushUndoSnapshot();
                    const range = sel.getRangeAt(0);

                    // If the selection exactly covers a <span> this editor already created (e.g.
                    // color was just applied to this same selection, and now size is being set
                    // too), reuse that span instead of wrapping another one around it - keeps
                    // "apply several properties to one selection in a row" from nesting spans.
                    const reusable = this.findReusableSpan(range, idoc);
                    if (reusable) {
                        this.setClassByRegex(reusable, regex, newClass);
                        const r = idoc.createRange();
                        r.selectNodeContents(reusable);
                        sel.removeAllRanges();
                        sel.addRange(r);
                        this._savedRange = r.cloneRange();
                        this.syncFromVisual();
                        return;
                    }

                    const frag = range.extractContents();
                    this.stripClassRecursive(frag, regex);

                    const span = idoc.createElement('span');
                    if (newClass) span.className = newClass;
                    span.appendChild(frag);
                    range.insertNode(span);

                    const r = idoc.createRange();
                    if (newClass) {
                        r.selectNodeContents(span);
                    } else {
                        // No class to apply - unwrap the helper span again, keep its contents selected.
                        const parent = span.parentNode;
                        const nodes = [...span.childNodes];
                        nodes.forEach((n) => parent.insertBefore(n, span));
                        parent.removeChild(span);
                        if (nodes.length) { r.setStartBefore(nodes[0]); r.setEndAfter(nodes[nodes.length - 1]); } else { r.selectNode(parent); }
                    }
                    sel.removeAllRanges();
                    sel.addRange(r);
                    this._savedRange = r.cloneRange();
                    this.syncFromVisual();
                },
                stripClassRecursive(node, regex) {
                    if (node.nodeType === 1) {
                        const cls = (node.getAttribute('class') || '').split(/\s+/).filter((c) => c && !regex.test(c));
                        if (cls.length) { node.setAttribute('class', cls.join(' ')); } else { node.removeAttribute('class'); }
                    }
                    [...(node.childNodes || [])].forEach((c) => this.stripClassRecursive(c, regex));
                },
                findReusableSpan(range, idoc) {
                    let node = range.commonAncestorContainer;
                    if (node.nodeType === 3) node = node.parentElement;
                    if (!node || node.tagName !== 'SPAN') return null;
                    const full = idoc.createRange();
                    full.selectNodeContents(node);
                    if (range.compareBoundaryPoints(Range.START_TO_START, full) === 0
                        && range.compareBoundaryPoints(Range.END_TO_END, full) === 0) {
                        return node;
                    }
                    return null;
                },
                applyAlignToBlock(newClass) {
                    this.restoreSelection();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    const sel = idoc.getSelection();
                    if (!sel || sel.rangeCount === 0) return;
                    let node = sel.getRangeAt(0).commonAncestorContainer;
                    if (node.nodeType === 3) node = node.parentElement;
                    const block = node && node.closest ? node.closest('p, h1, h2, h3, h4, h5, h6, li, blockquote, div') : null;
                    if (!block || block === idoc.body) return;
                    this.pushUndoSnapshot();
                    this.setClassByRegex(block, BLOG_EDITOR_ALIGN_RE, newClass);
                    this.syncFromVisual();
                },
                applyTextColorSelection() { this.applyClassToSelection(BLOG_EDITOR_COLOR_CLASS_RE, this.selColor); },
                applyTextSizeSelection() { this.applyClassToSelection(BLOG_EDITOR_SIZE_CLASS_RE, this.selSize); },
                applyHighlightSelection() { this.applyClassToSelection(BLOG_EDITOR_BG_CLASS_RE, this.selBg); },
                applyFontFamilySelection() { this.applyClassToSelection(BLOG_EDITOR_FONT_FAMILY_RE, this.selFontFamily); },
                applyFormatBlockSelect() { this.execCmd('formatBlock', this.selFormatTag); },
                execCmd(cmd, value, skipSnapshot) {
                    this.restoreSelection();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    if (!skipSnapshot) this.pushUndoSnapshot();
                    idoc.execCommand(cmd, false, value ?? null);
                    this.syncFromVisual();
                },
                toggleBold() { this.execCmd('bold'); },
                toggleItalic() { this.execCmd('italic'); },
                toggleUnderline() { this.execCmd('underline'); },
                insertList(ordered) { this.execCmd(ordered ? 'insertOrderedList' : 'insertUnorderedList'); },
                clearFormatting() {
                    this.pushUndoSnapshot();
                    this.execCmd('removeFormat', null, true);
                    [BLOG_EDITOR_COLOR_CLASS_RE, BLOG_EDITOR_SIZE_CLASS_RE, BLOG_EDITOR_BG_CLASS_RE, BLOG_EDITOR_FONT_FAMILY_RE].forEach((re) => {
                        this.applyClassToSelection(re, '', true);
                    });
                },
                toolbarLink() {
                    this.restoreSelection();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    const sel = idoc.getSelection();
                    if (!sel || sel.rangeCount === 0) return;
                    let node = sel.anchorNode;
                    if (node && node.nodeType === 3) node = node.parentElement;
                    const existingLink = node && node.closest ? node.closest('a') : null;
                    if (existingLink) { this.selectLink(existingLink); return; }
                    if (sel.isCollapsed) return;
                    this.pushUndoSnapshot();
                    const range = sel.getRangeAt(0);
                    const a = idoc.createElement('a');
                    a.setAttribute('href', '');
                    a.textContent = range.toString();
                    range.deleteContents();
                    range.insertNode(a);
                    this.syncFromVisual();
                    this.selectLink(a);
                },
                insertReadMore() {
                    this.restoreSelection();
                    this.pushUndoSnapshot();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    const existing = idoc.body.querySelector('hr.shorthr');
                    if (existing) existing.remove();
                    const hr = idoc.createElement('hr');
                    hr.className = 'shorthr';
                    const sel = idoc.getSelection();
                    if (sel && sel.rangeCount) {
                        const range = sel.getRangeAt(0);
                        range.collapse(true);
                        range.insertNode(hr);
                    } else {
                        idoc.body.appendChild(hr);
                    }
                    this.syncFromVisual();
                },
                openVideoPopover() { this.videoPopoverOpen = true; this.videoUrl = ''; },
                insertVideo() {
                    const url = (this.videoUrl || '').trim();
                    this.videoPopoverOpen = false;
                    if (!url) return;
                    this.restoreSelection();
                    this.pushUndoSnapshot();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    const yt = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([\w-]+)/);
                    const vimeo = !yt && url.match(/vimeo\.com\/(\d+)/);
                    let wrapper;
                    if (yt || vimeo) {
                        const iframeEl = idoc.createElement('iframe');
                        iframeEl.src = yt ? ('https://www.youtube.com/embed/' + yt[1]) : ('https://player.vimeo.com/video/' + vimeo[1]);
                        iframeEl.className = 'h-full w-full rounded-lg';
                        iframeEl.setAttribute('allowfullscreen', '');
                        wrapper = idoc.createElement('div');
                        wrapper.className = 'aspect-video mb-4';
                        wrapper.appendChild(iframeEl);
                    } else {
                        const videoEl = idoc.createElement('video');
                        videoEl.src = url;
                        videoEl.controls = true;
                        videoEl.className = 'w-full rounded-lg mb-4';
                        wrapper = videoEl;
                    }
                    const sel = idoc.getSelection();
                    if (sel && sel.rangeCount) {
                        const range = sel.getRangeAt(0);
                        range.collapse(false);
                        range.insertNode(wrapper);
                    } else {
                        idoc.body.appendChild(wrapper);
                    }
                    this.syncFromVisual();
                },
                setClassByRegex(el, re, newClass) {
                    const classes = (el.getAttribute('class') || '').split(/\s+/).filter((c) => c && !re.test(c));
                    if (newClass) classes.push(newClass);
                    const joined = classes.join(' ').trim();
                    if (joined) { el.setAttribute('class', joined); } else { el.removeAttribute('class'); }
                },
                selectImage(el) {
                    this.clearHighlight();
                    el.setAttribute('data-blogeditor-selected', '1');
                    this._selectedNode = el;
                    this.selectedKind = 'image';
                    this.imgAlt = el.getAttribute('alt') || '';
                    this.imgRounded = /(^|\s)rounded(-\S+)?(\s|$)/.test(el.getAttribute('class') || '');
                    this.showElementPopup('image', el);
                },
                applyImageAlt() {
                    if (!this._selectedNode) return;
                    this.pushUndoSnapshot();
                    this._selectedNode.setAttribute('alt', this.imgAlt);
                    this.syncFromVisual();
                },
                toggleImageRounded() {
                    if (!this._selectedNode) return;
                    this.pushUndoSnapshot();
                    this.setClassByRegex(this._selectedNode, /^rounded(-\S+)?$/, this.imgRounded ? 'rounded-lg' : '');
                    this.syncFromVisual();
                },
                removeImage() {
                    if (!this._selectedNode) return;
                    this.pushUndoSnapshot();
                    this._selectedNode.remove();
                    this.clearSelection();
                    this.hidePopup();
                    this.syncFromVisual();
                },
                replaceImageFile(event) {
                    const file = event.target.files[0];
                    if (!file || !this._selectedNode) { event.target.value = ''; return; }
                    const node = this._selectedNode;
                    this.readAndUploadImage(file, (url) => {
                        this.pushUndoSnapshot();
                        node.setAttribute('src', url);
                        this.syncFromVisual();
                    });
                    event.target.value = '';
                },
                addImageFile(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    this.readAndUploadImage(file, (url) => {
                        this.pushUndoSnapshot();
                        const frame = this.$refs.visualFrame;
                        const idoc = frame.contentDocument;
                        const img = idoc.createElement('img');
                        img.setAttribute('src', url);
                        img.setAttribute('alt', '');
                        img.setAttribute('class', 'rounded-lg max-w-full h-auto');
                        const anchor = (this._selectedNode && idoc.body.contains(this._selectedNode)) ? this._selectedNode : idoc.body.lastElementChild;
                        if (anchor && anchor.parentNode) {
                            anchor.parentNode.insertBefore(img, anchor.nextSibling);
                        } else {
                            idoc.body.appendChild(img);
                        }
                        this.syncFromVisual();
                    });
                    event.target.value = '';
                },
                readAndUploadImage(file, onDone) {
                    const reader = new FileReader();
                    this.uploadingImage = true;
                    reader.onload = () => {
                        this.$wire.call('uploadEditedImage', this.urlId, reader.result).then((url) => {
                            this.uploadingImage = false;
                            if (url) onDone(url);
                        });
                    };
                    reader.readAsDataURL(file);
                },
                selectLink(el) {
                    this.clearHighlight();
                    el.setAttribute('data-blogeditor-selected', '1');
                    this._selectedNode = el;
                    this.selectedKind = 'link';
                    this.linkHref = el.getAttribute('href') || '';
                    this.linkText = (el.textContent || '').trim();
                    this.linkTitle = el.getAttribute('title') || '';
                    this.linkTarget = el.getAttribute('target') || '_self';
                    const rel = (el.getAttribute('rel') || '').split(/\s+/);
                    this.linkNofollow = rel.includes('nofollow');
                    this.linkSponsored = rel.includes('sponsored');
                    this.linkUgc = rel.includes('ugc');
                    this.linkNoopener = rel.includes('noopener');
                    this.linkNoreferrer = rel.includes('noreferrer');
                    this.showElementPopup('link', el);
                },
                applyLink() {
                    if (!this._selectedNode) return;
                    this.pushUndoSnapshot();
                    const el = this._selectedNode;
                    el.setAttribute('href', this.linkHref);
                    if (this.linkText) el.textContent = this.linkText;
                    if (this.linkTitle) { el.setAttribute('title', this.linkTitle); } else { el.removeAttribute('title'); }
                    if (this.linkTarget && this.linkTarget !== '_self') { el.setAttribute('target', this.linkTarget); } else { el.removeAttribute('target'); }
                    const rel = [];
                    if (this.linkNofollow) rel.push('nofollow');
                    if (this.linkSponsored) rel.push('sponsored');
                    if (this.linkUgc) rel.push('ugc');
                    if (this.linkNoopener) rel.push('noopener');
                    if (this.linkNoreferrer) rel.push('noreferrer');
                    if (rel.length) { el.setAttribute('rel', rel.join(' ')); } else { el.removeAttribute('rel'); }
                    this.syncFromVisual();
                },

                // ---- code mode: CodeMirror, lazily created on first use per language tab.
                initOrRefreshCode() {
                    const outer = this.$refs.codeContainer;
                    if (!outer) return;
                    // CodeMirror mounts into the inner x-ignore'd div, not the ref'd element
                    // itself - see the comment beside it in the blade for why.
                    const el = outer.firstElementChild;
                    if (!el) return;

                    if (typeof CodeMirror === 'undefined') {
                        // The CodeMirror assets, streamed through /editor-assets/, failed to
                        // load - either a stale cached Blade view after a deploy (run
                        // `php artisan view:clear`) or resources/vendor-js/codemirror genuinely
                        // missing on the server. Fails loud instead of a blank box, and points
                        // at how to tell which.
                        el.innerHTML = '<p class="p-3 text-sm text-danger-600">Code editor failed to load (CodeMirror script missing). Open <code>{{ url("/editor-assets/codemirror/codemirror.js") }}</code> directly in a new tab - if that 404s, resources/vendor-js/codemirror wasn\'t deployed; if it loads fine, try a hard refresh and <code>php artisan view:clear</code> on the server instead.</p>';
                        return;
                    }

                    if (!this.codeMirror) {
                        this.codeMirror = CodeMirror(el, {
                            value: this.html,
                            mode: 'htmlmixed',
                            lineNumbers: true,
                            lineWrapping: true,
                            viewportMargin: Infinity,
                        });
                        // Filament's modal has its own focus-trap (a separate MutationObserver
                        // from Alpine's/Livewire's, watching the trapped region to keep its
                        // tabbable-elements list current) that reacts to CodeMirror's constant
                        // internal DOM churn (cursor/line nodes) often enough to interrupt one of
                        // CodeMirror's own operations mid-flight, throwing "Cannot read
                        // properties of undefined (reading 'map')" from deep inside
                        // codemirror.js - happens with either input style (textarea or
                        // contenteditable), so it's the trap doing this, not CodeMirror's input
                        // handling. A full refresh() right after each edit (deferred off
                        // CodeMirror's own synchronous operation, which also avoids a second,
                        // separate reentrancy from writing the reactive `html` property
                        // mid-operation) rebuilds its internal view from scratch and reliably
                        // redraws the cursor/selection correctly - the one interrupted operation
                        // per keystroke is harmless as long as this follows it every time.
                        this.codeMirror.on('change', (cmInstance) => {
                            const value = cmInstance.getValue();
                            setTimeout(() => {
                                this.html = value;
                                cmInstance.refresh();
                            }, 0);
                        });
                    } else {
                        this.codeMirror.setValue(this.html);
                    }
                    setTimeout(() => this.codeMirror.refresh(), 30);
                },
                syncFromCode() {
                    if (this.codeMirror) this.html = this.codeMirror.getValue();
                },

                save() {
                    if (this.mode === 'visual') this.syncFromVisual();
                    if (this.mode === 'code') this.syncFromCode();
                    this.saving = true;
                    this.$wire.call('saveEditedContent', this.urlId, this.html).then(() => {
                        this.saving = false;
                        this.editedPreviewUrl = editedPreviewUrlTemplate;
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
