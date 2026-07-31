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

                    <div class="flex flex-wrap gap-1">
                        @if (! $language['isDefault'] && ! $language['isTranslated'])
                            <x-filament::button
                                size="sm"
                                color="gray"
                                icon="heroicon-o-sparkles"
                                wire:click="translateWithAi({{ $language['urlId'] }})"
                            >
                                Translate with AI
                            </x-filament::button>
                        @endif

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
                </div>

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

                <dl class="mb-4 space-y-1.5 text-sm">
                    @foreach ($copyFields as $label => $value)
                        <div class="flex items-start justify-between gap-2" x-data="{ copied: false }">
                            <div class="min-w-0 flex-1">
                                <dt class="inline font-medium text-gray-700 dark:text-gray-200">{{ $label }}:</dt>
                                <dd class="inline text-gray-600 dark:text-gray-300">{{ $value ?? '—' }}</dd>
                            </div>
                            @if ($value)
                                <button
                                    type="button"
                                    @click="navigator.clipboard.writeText({{ Illuminate\Support\Js::from($value) }}); copied = true; setTimeout(() => copied = false, 1500)"
                                    class="shrink-0 text-xs text-gray-400 hover:text-primary-600 dark:hover:text-primary-400"
                                    x-text="copied ? 'Copied!' : 'Copy'"
                                ></button>
                            @endif
                        </div>
                    @endforeach
                </dl>

                <div
                    x-data="blogEditor(
                        {{ $language['urlId'] }},
                        {{ Illuminate\Support\Js::from($language['contentHtml'] ?? '') }},
                        {{ Illuminate\Support\Js::from($language['editedContent'] ?? $language['contentHtml'] ?? '') }},
                        {{ Illuminate\Support\Js::from($language['editedPreviewUrl']) }},
                        {{ Illuminate\Support\Js::from($language['editedPreviewUrlTemplate']) }}
                    )"
                >
                    <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                        <div class="flex gap-1 rounded-lg bg-gray-100 p-0.5 dark:bg-white/5">
                            <button
                                type="button"
                                @click="mode = 'original'"
                                :class="mode === 'original' ? 'bg-white shadow-sm dark:bg-gray-700' : 'text-gray-500 dark:text-gray-400'"
                                class="rounded-md px-3 py-1 text-xs font-medium"
                            >Original</button>
                            <button
                                type="button"
                                @click="mode = 'editor'"
                                :class="mode === 'editor' ? 'bg-white shadow-sm dark:bg-gray-700' : 'text-gray-500 dark:text-gray-400'"
                                class="rounded-md px-3 py-1 text-xs font-medium"
                            >Editor</button>
                        </div>

                        <div x-show="mode === 'original'">
                            <button
                                type="button"
                                @click="copyOriginal()"
                                class="text-xs text-gray-400 hover:text-primary-600 dark:hover:text-primary-400"
                                x-text="copiedOriginal ? 'Copied!' : 'Copy original HTML'"
                            ></button>
                        </div>
                    </div>

                    <div x-show="mode === 'original'">
                        @if ($language['previewUrl'])
                            <iframe
                                src="{{ $language['previewUrl'] }}"
                                class="h-96 w-full rounded-lg border border-gray-200 dark:border-white/10"
                            ></iframe>
                        @else
                            <div class="flex h-40 items-center justify-center rounded-lg border border-dashed border-gray-300 text-sm text-gray-400 dark:border-white/10 dark:text-gray-500">
                                Content not extracted yet - click "Extract content" above.
                            </div>
                        @endif
                    </div>

                    <div x-show="mode === 'editor'" x-cloak>
                        <div class="mb-2 flex flex-wrap items-center gap-2">
                            <button type="button" @click="scanImages()" class="rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                                🖼 Fix image ALT text
                            </button>
                            <button type="button" @click="scanLinks()" class="rounded-md border border-gray-200 px-2 py-1 text-xs text-gray-600 hover:bg-gray-50 dark:border-white/10 dark:text-gray-300 dark:hover:bg-white/5">
                                🔗 Fix links
                            </button>

                            <span class="flex-1"></span>

                            <button type="button" @click="copyEdited()" class="text-xs text-gray-400 hover:text-primary-600 dark:hover:text-primary-400" x-text="copiedEdited ? 'Copied!' : 'Copy edited HTML'"></button>
                            <a x-show="editedPreviewUrl" :href="editedPreviewUrl" target="_blank" rel="noopener" class="text-xs text-primary-600 hover:underline dark:text-primary-400">Preview edited ↗</a>
                            <x-filament::button size="sm" @click="save()" :disabled="false">
                                <span x-text="saving ? 'Saving…' : 'Save'"></span>
                            </x-filament::button>
                        </div>

                        <div x-show="showImages" x-cloak class="mb-3 max-h-48 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-white/10">
                            <template x-if="images.length === 0">
                                <p class="text-xs text-gray-400">No images found in this content.</p>
                            </template>
                            <template x-for="(img, i) in images" :key="i">
                                <div class="flex items-center gap-2">
                                    <img :src="img.src" class="h-8 w-8 shrink-0 rounded border border-gray-200 object-cover dark:border-white/10" onerror="this.style.visibility='hidden'">
                                    <input type="text" x-model="img.alt" placeholder="Alt text..." class="fi-input flex-1 rounded-md border-gray-300 text-xs dark:border-white/10 dark:bg-gray-900">
                                </div>
                            </template>
                            <x-filament::button size="xs" color="gray" @click="applyImages()" x-show="images.length > 0">
                                Apply to content
                            </x-filament::button>
                        </div>

                        <div x-show="showLinks" x-cloak class="mb-3 max-h-48 space-y-2 overflow-y-auto rounded-md border border-gray-200 p-2 dark:border-white/10">
                            <template x-if="links.length === 0">
                                <p class="text-xs text-gray-400">No links found in this content.</p>
                            </template>
                            <template x-for="(link, i) in links" :key="i">
                                <div class="flex items-center gap-2">
                                    <span class="w-32 shrink-0 truncate text-xs text-gray-500 dark:text-gray-400" x-text="link.text"></span>
                                    <input type="text" x-model="link.href" class="fi-input flex-1 rounded-md border-gray-300 text-xs dark:border-white/10 dark:bg-gray-900">
                                </div>
                            </template>
                            <x-filament::button size="xs" color="gray" @click="applyLinks()" x-show="links.length > 0">
                                Apply to content
                            </x-filament::button>
                        </div>

                        <textarea
                            x-model="html"
                            spellcheck="false"
                            class="fi-input h-72 w-full rounded-lg border-gray-200 p-2 font-mono text-xs dark:border-white/10 dark:bg-gray-900"
                        ></textarea>

                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500" x-show="!editedPreviewUrl">
                            Edit the HTML above, then Save to create an edited preview link.
                        </p>
                    </div>
                </div>
            @endif
        </div>
    @endforeach
</div>
