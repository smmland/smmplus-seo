@php
    $languages = $languages instanceof \Illuminate\Support\Collection ? $languages : collect($languages);
@endphp

<div x-data="{ tab: '{{ $defaultLangCode }}' }">
    @if ($englishRow)
        <div class="mb-4 space-y-1">
            <p class="text-sm text-gray-500 dark:text-gray-400">
                <a href="{{ $englishRow->source_url }}" target="_blank" rel="noopener" class="hover:underline">
                    {{ $englishRow->source_url }}
                </a>
            </p>

            @if ($englishRow->seo_title)
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    &lt;title&gt; {{ $englishRow->seo_title }}
                </p>
            @endif

            @if ($englishRow->meta_description)
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    {{ $englishRow->meta_description }}
                </p>
            @endif
        </div>
    @endif

    <div class="flex flex-wrap gap-1 border-b border-gray-200 dark:border-white/10 mb-4">
        @foreach ($languages as $language)
            <button
                type="button"
                @click="tab = '{{ $language['code'] }}'"
                :class="tab === '{{ $language['code'] }}' ? 'border-primary-600 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 dark:text-gray-400'"
                class="flex items-center gap-1 border-b-2 -mb-px px-3 py-2 text-sm font-medium"
            >
                {{ strtoupper($language['code']) }}

                @if ($language['isDefault'])
                    <x-filament::icon icon="heroicon-m-star" class="h-4 w-4 text-gray-400 dark:text-gray-500" />
                @elseif (! $language['exists'])
                    <x-filament::icon icon="heroicon-m-minus-circle" class="h-4 w-4 text-gray-300 dark:text-gray-600" />
                @elseif ($language['isTranslated'])
                    <x-filament::icon icon="heroicon-m-check-circle" class="h-4 w-4 text-success-500" />
                @else
                    <x-filament::icon icon="heroicon-m-x-circle" class="h-4 w-4 text-warning-500" />
                @endif
            </button>
        @endforeach
    </div>

    @foreach ($languages as $language)
        <div x-show="tab === '{{ $language['code'] }}'" x-cloak>
            @if (! $language['exists'])
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    No {{ $language['name'] }} page exists yet for this topic.
                </p>
            @else
                <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                    <a
                        href="{{ $language['sourceUrl'] }}"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex items-center gap-1 text-sm text-primary-600 hover:underline dark:text-primary-400"
                    >
                        Open live page
                        <x-filament::icon icon="heroicon-m-arrow-top-right-on-square" class="h-4 w-4" />
                    </a>

                    <x-filament::button
                        size="sm"
                        color="gray"
                        icon="heroicon-o-arrow-path"
                        wire:click="extractContent({{ $language['urlId'] }})"
                        wire:loading.attr="disabled"
                        wire:target="extractContent({{ $language['urlId'] }})"
                    >
                        {{ $language['contentExtracted'] ? 'Re-extract content' : 'Extract content' }}
                    </x-filament::button>
                </div>

                <dl class="mb-3 space-y-1 text-sm">
                    <div>
                        <dt class="inline font-medium text-gray-700 dark:text-gray-200">Title:</dt>
                        <dd class="inline text-gray-600 dark:text-gray-300">{{ $language['articleTitle'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium text-gray-700 dark:text-gray-200">&lt;title&gt;:</dt>
                        <dd class="inline text-gray-600 dark:text-gray-300">{{ $language['seoTitle'] ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="inline font-medium text-gray-700 dark:text-gray-200">Meta description:</dt>
                        <dd class="inline text-gray-600 dark:text-gray-300">{{ $language['metaDescription'] ?? '—' }}</dd>
                    </div>
                </dl>

                @if ($language['previewUrl'])
                    <iframe
                        src="{{ $language['previewUrl'] }}"
                        class="h-96 w-full rounded-lg border border-gray-200 dark:border-white/10"
                    ></iframe>
                @else
                    <p class="text-sm text-gray-400 dark:text-gray-500">
                        Content not extracted yet - click "Extract content" above.
                    </p>
                @endif
            @endif
        </div>
    @endforeach
</div>
