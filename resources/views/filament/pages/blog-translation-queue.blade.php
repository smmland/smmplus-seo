<x-filament-panels::page>
    <div wire:poll.60s>
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
    </div>

    <x-filament::section>
        @if ($this->queue->isEmpty())
            <p class="text-sm text-gray-500 dark:text-gray-400">
                No blog topics have missing translations right now.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="fi-ta-table w-full text-start">
                    <thead>
                        <tr>
                            <th class="p-2 text-start text-sm font-semibold">#</th>
                            <th class="p-2 text-start text-sm font-semibold">Topic (default language)</th>
                            <th class="p-2 text-start text-sm font-semibold">Missing languages</th>
                            <th class="p-2 text-start text-sm font-semibold">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($this->queue as $topic)
                            <tr wire:key="topic-{{ $topic['url']->id }}" class="border-t border-gray-100 dark:border-white/5 align-top">
                                <td class="p-2 text-sm text-gray-500 dark:text-gray-400">
                                    {{ $loop->iteration }}
                                </td>
                                <td class="p-2">
                                    <a href="{{ $topic['url']->source_url }}" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">
                                        {{ $topic['url']->slug }}
                                    </a>
                                </td>
                                <td class="p-2">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach ($topic['missing'] as $language)
                                            <x-filament::badge color="warning">
                                                {{ $language->code }}
                                            </x-filament::badge>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="p-2">
                                    <div class="flex flex-wrap gap-1">
                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            icon="heroicon-o-arrow-path"
                                            wire:click="recheckTopic({{ Illuminate\Support\Js::from($topic['url']->group_key) }})"
                                            wire:loading.attr="disabled"
                                            wire:target="recheckTopic({{ Illuminate\Support\Js::from($topic['url']->group_key) }})"
                                        >
                                            Recheck
                                        </x-filament::button>

                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            icon="heroicon-o-photo"
                                            wire:click="extractContent({{ $topic['url']->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="extractContent({{ $topic['url']->id }})"
                                        >
                                            Extract content
                                        </x-filament::button>

                                        <x-filament::button
                                            size="sm"
                                            color="gray"
                                            icon="heroicon-o-information-circle"
                                            wire:click="mountAction('viewTopic', {{ Illuminate\Support\Js::from(['groupKey' => $topic['url']->group_key, 'title' => $topic['url']->article_title ?? $topic['url']->slug]) }})"
                                        >
                                            Details
                                        </x-filament::button>
                                    </div>

                                    @if ($topic['url']->content_extracted_at)
                                        <p class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                                            Last extracted {{ $topic['url']->content_extracted_at->diffForHumans() }}
                                        </p>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    {{-- Defined here (not in the modal's own view) because Filament's action modal content is
         injected into the DOM dynamically (Livewire morph), and browsers never execute <script>
         tags inserted that way - only ones present in a real page load, like this one. --}}
    <script>
        function blogEditor(urlId, originalHtml, editedHtml, editedPreviewUrl, editedPreviewUrlTemplate) {
            return {
                urlId: urlId,
                mode: 'original',
                html: editedHtml || originalHtml || '',
                editedPreviewUrl: editedPreviewUrl || null,
                saving: false,
                copiedOriginal: false,
                copiedEdited: false,
                showImages: false,
                showLinks: false,
                images: [],
                links: [],
                copyOriginal() {
                    navigator.clipboard.writeText(originalHtml || '');
                    this.copiedOriginal = true;
                    setTimeout(() => { this.copiedOriginal = false; }, 1500);
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
                    this.showImages = false;
                },
                scanLinks() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    this.links = [...doc.querySelectorAll('a[href]')].map((a, i) => ({
                        index: i,
                        href: a.getAttribute('href') || '',
                        text: (a.textContent || '').trim().slice(0, 40),
                    }));
                    this.showLinks = true;
                    this.showImages = false;
                },
                applyLinks() {
                    const doc = new DOMParser().parseFromString(this.html, 'text/html');
                    const links = doc.querySelectorAll('a[href]');
                    this.links.forEach((item) => {
                        if (links[item.index]) {
                            links[item.index].setAttribute('href', item.href);
                        }
                    });
                    this.html = doc.body.innerHTML;
                    this.showLinks = false;
                },
                save() {
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
