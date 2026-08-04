<div>
    <p class="mb-4 text-sm text-gray-500 dark:text-gray-400">
        {{ $categoryTitle ?? 'Uncategorized' }} · id {{ $serviceKey }}
    </p>

    <div class="space-y-4">
        @foreach ($languages as $language)
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-semibold text-gray-950 dark:text-white">
                            {{ $language['name'] }} ({{ strtoupper($language['code']) }})
                        </span>

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
                        @elseif ($language['pending'])
                            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                <x-filament::loading-indicator class="h-3 w-3" />
                                Translating…
                            </span>
                        @elseif ($language['isTranslated'])
                            <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium" style="background-color: rgba(var(--success-500), .1); color: rgb(var(--success-700))">
                                <x-filament::icon icon="heroicon-m-check-circle" class="h-3 w-3" />
                                Translated
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-500 dark:bg-white/10 dark:text-gray-400">
                                Not translated
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
                    <div class="mt-3 rounded-lg bg-gray-50 p-3 text-sm text-gray-700 dark:bg-white/5 dark:text-gray-300" dir="{{ \App\Models\Language::direction($language['code']) }}">
                        {!! $language['description'] !!}
                    </div>
                @else
                    <p class="mt-3 text-xs text-gray-400 dark:text-gray-500">No description yet.</p>
                @endif

                @if ($language['checkedAt'])
                    <p class="mt-2 text-xs text-gray-400 dark:text-gray-500">
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
