<div
    x-data="{
        exportOpen: false,
        exportSelected: {{ Illuminate\Support\Js::from($languages->mapWithKeys(fn ($l) => [$l['code'] => $l['exists'] && ! $l['isDefault']])->all()) }},
        titleExportOpen: false,
        titleExportSelected: {{ Illuminate\Support\Js::from($languages->mapWithKeys(fn ($l) => [$l['code'] => $l['exists'] && ! $l['isDefault']])->all()) }},
    }"
>
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {{ $categoryTitle ?? 'Uncategorized' }} · id {{ $serviceKey }}
    </p>

    @if ($hasDescription)
        <div class="mb-3 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
            <button type="button" @click="exportOpen = !exportOpen" class="flex w-full items-center justify-between gap-2 text-start text-sm font-medium text-gray-950 dark:text-white">
                <span class="flex items-center gap-2">
                    <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4 text-gray-400" />
                    Download description export
                </span>
                <x-filament::icon icon="heroicon-o-chevron-down" x-show="!exportOpen" class="h-4 w-4 text-gray-400" />
                <x-filament::icon icon="heroicon-o-chevron-up" x-show="exportOpen" x-cloak class="h-4 w-4 text-gray-400" />
            </button>

            <div x-show="exportOpen" x-cloak class="mt-3 space-y-3">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    Pick which languages to include, then download a zip with one text file per language ({{ '{lang}.txt' }}) containing just that language's description.
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
                                    @elseif ($language['isTranslated'] && ! $language['needsSiteUpdate'])
                                        <x-filament::badge color="success" size="xs">Uploaded</x-filament::badge>
                                    @elseif ($language['isTranslated'])
                                        <x-filament::badge color="warning" size="xs">Needs upload</x-filament::badge>
                                    @elseif ($language['error'])
                                        <x-filament::badge color="danger" size="xs">Error</x-filament::badge>
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
                        @click="$wire.downloadServiceExport({{ Illuminate\Support\Js::from($serviceKey) }}, Object.keys(exportSelected).filter(code => exportSelected[code]))"
                    >
                        Download zip
                    </x-filament::button>
                </div>
            </div>
        </div>
    @endif

    {{-- Separate panel entirely from the description export above - never combined into the
         same zip or file, per how title translation is tracked/queued/downloaded throughout
         this page. --}}
    <div class="mb-4 rounded-xl bg-gray-50 p-4 ring-1 ring-gray-950/5 dark:bg-white/5 dark:ring-white/10">
        <button type="button" @click="titleExportOpen = !titleExportOpen" class="flex w-full items-center justify-between gap-2 text-start text-sm font-medium text-gray-950 dark:text-white">
            <span class="flex items-center gap-2">
                <x-filament::icon icon="heroicon-o-arrow-down-tray" class="h-4 w-4 text-gray-400" />
                Download title export
            </span>
            <x-filament::icon icon="heroicon-o-chevron-down" x-show="!titleExportOpen" class="h-4 w-4 text-gray-400" />
            <x-filament::icon icon="heroicon-o-chevron-up" x-show="titleExportOpen" x-cloak class="h-4 w-4 text-gray-400" />
        </button>

        <div x-show="titleExportOpen" x-cloak class="mt-3 space-y-3">
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Pick which languages to include, then download a zip with one text file per language ({{ '{lang}.txt' }}) containing just that language's title.
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
                                    x-model="titleExportSelected['{{ $language['code'] }}']"
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
                                @elseif ($language['titleIsTranslated'] && ! $language['titleNeedsSiteUpdate'])
                                    <x-filament::badge color="success" size="xs">Uploaded</x-filament::badge>
                                @elseif ($language['titleIsTranslated'])
                                    <x-filament::badge color="warning" size="xs">Needs upload</x-filament::badge>
                                @elseif ($language['titleError'])
                                    <x-filament::badge color="danger" size="xs">Error</x-filament::badge>
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
                    @click="$wire.downloadServiceTitleExport({{ Illuminate\Support\Js::from($serviceKey) }}, Object.keys(titleExportSelected).filter(code => titleExportSelected[code]))"
                >
                    Download zip
                </x-filament::button>
            </div>
        </div>
    </div>

    <div class="space-y-4">
        @foreach ($languages as $language)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <span class="text-sm font-semibold text-gray-950 dark:text-white">
                    {{ $language['name'] }} ({{ strtoupper($language['code']) }})
                </span>

                {{-- Title and description are shown as two clearly separate subsections - own
                     status badge, own translate button, own content - never merged into one
                     status the way they briefly were before title tracking existed. --}}
                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-white/5">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-medium text-gray-400 dark:text-gray-500">Title</span>

                            {{-- Warning/success shades colored via inline styles reading Filament's own
                                 --warning-*/--success-* CSS custom properties, not
                                 bg-warning-50/text-success-700-style utility classes - this admin panel
                                 serves Filament's pre-built CSS bundle, which wasn't compiled with those
                                 specific shades since nothing else in it happens to use them (same root
                                 cause documented in blog-translation-details.blade.php). --}}
                            @if ($language['isDefault'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--warning-500), .1); color: rgb(var(--warning-700))">
                                    Default language
                                </span>
                            @elseif ($language['titlePending'])
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    <x-filament::loading-indicator class="h-3 w-3" />
                                    Translating…
                                </span>
                            @elseif ($language['titleIsTranslated'] && ! $language['titleNeedsSiteUpdate'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--success-500), .1); color: rgb(var(--success-700))">
                                    <span class="inline-flex">
                                        <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" />
                                        <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" style="margin-left: -6px" />
                                    </span>
                                    Uploaded
                                </span>
                            @elseif ($language['titleIsTranslated'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--warning-500), .1); color: rgb(var(--warning-700))">
                                    <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-3 w-3" />
                                    Needs upload
                                </span>
                            @elseif ($language['titleError'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3 w-3" />
                                    Error
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    Not translated
                                </span>
                            @endif

                            @if ($language['titleRetranslated'] ?? false)
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--primary-500), .1); color: rgb(var(--primary-700))" title="The source title changed, so this was automatically re-translated">
                                    <x-filament::icon icon="heroicon-m-arrow-path" class="h-3 w-3" />
                                    Auto re-translated
                                </span>
                            @endif
                        </div>

                        @if (! $language['isDefault'] && ! $language['titlePending'])
                            <x-filament::button
                                size="xs"
                                color="gray"
                                icon="heroicon-o-tag"
                                wire:click="translateTitle({{ Illuminate\Support\Js::from($serviceKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                wire:loading.attr="disabled"
                            >
                                {{ $language['titleIsTranslated'] ? 'Re-translate with AI' : 'Translate with AI' }}
                            </x-filament::button>
                        @endif
                    </div>

                    @if ($language['titleError'])
                        <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $language['titleError'] }}</p>
                    @endif

                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-300" dir="{{ \App\Models\Language::direction($language['code']) }}">
                        {{ $language['title'] ?: 'No title yet.' }}
                    </p>
                </div>

                <div class="mt-3 border-t border-gray-100 pt-3 dark:border-white/5">
                    @if (! $hasDescription)
                        <span class="text-xs font-medium text-gray-400 dark:text-gray-500">Description</span>
                        <p class="mt-2 text-xs italic text-gray-400 dark:text-gray-500">No description on the site for this service - nothing to translate.</p>
                    @else
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-medium text-gray-400 dark:text-gray-500">Description</span>

                            @if ($language['isDefault'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--warning-500), .1); color: rgb(var(--warning-700))">
                                    Default language
                                </span>
                            @elseif ($language['pending'])
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    <x-filament::loading-indicator class="h-3 w-3" />
                                    Translating…
                                </span>
                            @elseif ($language['isTranslated'] && ! $language['needsSiteUpdate'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--success-500), .1); color: rgb(var(--success-700))">
                                    <span class="inline-flex">
                                        <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" />
                                        <x-filament::icon icon="heroicon-m-check" class="h-3 w-3" style="margin-left: -6px" />
                                    </span>
                                    Uploaded
                                </span>
                            @elseif ($language['isTranslated'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--warning-500), .1); color: rgb(var(--warning-700))">
                                    <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-3 w-3" />
                                    Needs upload
                                </span>
                            @elseif ($language['error'])
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--danger-500), .1); color: rgb(var(--danger-700))">
                                    <x-filament::icon icon="heroicon-m-exclamation-triangle" class="h-3 w-3" />
                                    Error
                                </span>
                            @else
                                <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                    Not translated
                                </span>
                            @endif

                            @if ($language['retranslated'] ?? false)
                                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--primary-500), .1); color: rgb(var(--primary-700))" title="The source description changed, so this was automatically re-translated">
                                    <x-filament::icon icon="heroicon-m-arrow-path" class="h-3 w-3" />
                                    Auto re-translated
                                </span>
                            @endif
                        </div>

                        @if (! $language['isDefault'] && ! $language['pending'])
                            <x-filament::button
                                size="xs"
                                color="gray"
                                icon="heroicon-o-language"
                                wire:click="translateLanguage({{ Illuminate\Support\Js::from($serviceKey) }}, {{ Illuminate\Support\Js::from($language['code']) }})"
                                wire:loading.attr="disabled"
                            >
                                {{ $language['isTranslated'] ? 'Re-translate with AI' : 'Translate with AI' }}
                            </x-filament::button>
                        @endif
                    </div>

                    @if ($language['error'])
                        <p class="mt-2 text-xs text-danger-600 dark:text-danger-400">{{ $language['error'] }}</p>
                    @endif

                    @if ($language['description'])
                        <div class="mt-2 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300" dir="{{ \App\Models\Language::direction($language['code']) }}">
                            {!! $language['description'] !!}
                        </div>
                    @else
                        <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">No description yet.</p>
                    @endif
                    @endif
                </div>

                @if ($language['checkedAt'])
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">
                        Last checked {{ $language['checkedAt']->diffForHumans() }}
                        @if ($language['checkNote'])
                            · {{ $language['checkNote'] }}
                        @endif
                    </p>
                @endif
            </div>
        @endforeach
    </div>
</div>
