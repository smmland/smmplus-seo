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

    {{-- Code-editor mode uses CodeMirror, vendored under public/vendor/codemirror (this app
         ships with no JS build step, so a plain static include - not an npm/CDN dependency -
         matches how the rest of the admin panel's assets already work). --}}
    <link rel="stylesheet" href="{{ asset('vendor/codemirror/codemirror.css') }}">
    <script src="{{ asset('vendor/codemirror/codemirror.js') }}"></script>
    <script src="{{ asset('vendor/codemirror/mode/xml/xml.js') }}"></script>
    <script src="{{ asset('vendor/codemirror/mode/javascript/javascript.js') }}"></script>
    <script src="{{ asset('vendor/codemirror/mode/css/css.js') }}"></script>
    <script src="{{ asset('vendor/codemirror/mode/htmlmixed/htmlmixed.js') }}"></script>

    {{-- Defined here (not in the modal's own view) because Filament's action modal content is
         injected into the DOM dynamically (Livewire morph), and browsers never execute <script>
         tags inserted that way - only ones present in a real page load, like this one. --}}
    <script>
        const BLOG_EDITOR_TEXT_COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'indigo', 'purple'];
        const BLOG_EDITOR_TEXT_SHADES = ['400', '500', '600', '700', '900'];
        const BLOG_EDITOR_TEXT_SIZES = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'];
        const BLOG_EDITOR_COLOR_CLASS_RE = /^text-(gray|red|orange|yellow|green|blue|indigo|purple)-(50|100|200|300|400|500|600|700|800|900)$/;
        const BLOG_EDITOR_SIZE_CLASS_RE = /^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl)$/;

        function blogEditor(urlId, originalHtml, editedHtml, editedPreviewUrl, editedPreviewUrlTemplate) {
            return {
                urlId: urlId,
                mode: 'original',
                html: editedHtml || originalHtml || '',
                editedPreviewUrl: editedPreviewUrl || null,
                saving: false,
                copiedOriginal: false,
                copiedEdited: false,

                // Code mode - bulk find/replace tools, operate on the raw html string.
                showImages: false,
                showLinks: false,
                images: [],
                links: [],
                codeMirror: null,

                // Visual mode - click-to-select inspector.
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

                textColors: BLOG_EDITOR_TEXT_COLORS,
                textShades: BLOG_EDITOR_TEXT_SHADES,
                textSizes: BLOG_EDITOR_TEXT_SIZES,

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

                // ---- mode switching - each mode holds its own live copy of the content, so
                // switching away from one syncs it back into `html` before the next mode reads it.
                goOriginal() {
                    if (this.mode === 'visual') this.syncFromVisual();
                    if (this.mode === 'code') this.syncFromCode();
                    this.mode = 'original';
                },
                goVisual() {
                    if (this.mode === 'code') this.syncFromCode();
                    this.mode = 'visual';
                    this.$nextTick(() => this.renderVisual());
                },
                goCode() {
                    if (this.mode === 'visual') this.syncFromVisual();
                    this.mode = 'code';
                    this.$nextTick(() => this.initOrRefreshCode());
                },

                // ---- visual mode: a contenteditable iframe (Tailwind CDN, so every utility
                // class renders exactly like the live site) plus click-to-select element inspector.
                renderVisual() {
                    const frame = this.$refs.visualFrame;
                    if (!frame) return;
                    this.clearSelection();
                    // Built with split closing tags ("<" + "/head>" etc.) because Livewire's
                    // asset injector does a raw string search across the whole rendered response
                    // for the literal head/body closing tags and splices its own script/style
                    // tags in wherever it finds them - including inside this JS string literal,
                    // which corrupted it into broken, unparseable JS when written as normal text.
                    const doc = '<!doctype html><html><head><meta charset="utf-8">'
                        + '<script src="https://cdn.tailwindcss.com"><' + '/script>'
                        + '<style>body{padding:1.5rem;max-width:48rem;margin:0 auto;outline:none;}'
                        + '[data-blogeditor-selected]{outline:2px solid #6366f1;outline-offset:2px;}</style>'
                        + '<' + '/head><body contenteditable="true">' + this.html + '<' + '/body><' + '/html>';
                    frame.onload = () => {
                        const idoc = frame.contentDocument;
                        idoc.body.addEventListener('input', () => this.syncFromVisual());
                        idoc.body.addEventListener('click', (e) => this.visualClick(e));
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
                visualClick(e) {
                    const frame = this.$refs.visualFrame;
                    const body = frame.contentDocument.body;

                    if (e.target === body) { this.clearSelection(); return; }
                    if (e.target.tagName === 'IMG') { this.selectImage(e.target); return; }

                    const link = e.target.closest ? e.target.closest('a') : null;
                    if (link) { this.selectLink(link); return; }

                    this.selectText(e.target);
                },
                selectText(el) {
                    this.clearHighlight();
                    el.setAttribute('data-blogeditor-selected', '1');
                    this._selectedNode = el;
                    this.selectedKind = 'text';
                    const classes = (el.getAttribute('class') || '').split(/\s+/);
                    this.selColor = classes.find((c) => BLOG_EDITOR_COLOR_CLASS_RE.test(c)) || '';
                    this.selSize = classes.find((c) => BLOG_EDITOR_SIZE_CLASS_RE.test(c)) || '';
                },
                setClassByRegex(el, re, newClass) {
                    const classes = (el.getAttribute('class') || '').split(/\s+/).filter((c) => c && !re.test(c));
                    if (newClass) classes.push(newClass);
                    const joined = classes.join(' ').trim();
                    if (joined) { el.setAttribute('class', joined); } else { el.removeAttribute('class'); }
                },
                applyTextColor() {
                    if (!this._selectedNode) return;
                    this.setClassByRegex(this._selectedNode, BLOG_EDITOR_COLOR_CLASS_RE, this.selColor);
                    this.syncFromVisual();
                },
                applyTextSize() {
                    if (!this._selectedNode) return;
                    this.setClassByRegex(this._selectedNode, BLOG_EDITOR_SIZE_CLASS_RE, this.selSize);
                    this.syncFromVisual();
                },
                selectImage(el) {
                    this.clearHighlight();
                    el.setAttribute('data-blogeditor-selected', '1');
                    this._selectedNode = el;
                    this.selectedKind = 'image';
                    this.imgAlt = el.getAttribute('alt') || '';
                    this.imgRounded = /(^|\s)rounded(-\S+)?(\s|$)/.test(el.getAttribute('class') || '');
                },
                applyImageAlt() {
                    if (!this._selectedNode) return;
                    this._selectedNode.setAttribute('alt', this.imgAlt);
                    this.syncFromVisual();
                },
                toggleImageRounded() {
                    if (!this._selectedNode) return;
                    this.setClassByRegex(this._selectedNode, /^rounded(-\S+)?$/, this.imgRounded ? 'rounded-lg' : '');
                    this.syncFromVisual();
                },
                removeImage() {
                    if (!this._selectedNode) return;
                    this._selectedNode.remove();
                    this.clearSelection();
                    this.syncFromVisual();
                },
                replaceImageFile(event) {
                    const file = event.target.files[0];
                    if (!file || !this._selectedNode) { event.target.value = ''; return; }
                    const node = this._selectedNode;
                    this.readAndUploadImage(file, (url) => {
                        node.setAttribute('src', url);
                        this.syncFromVisual();
                    });
                    event.target.value = '';
                },
                addImageFile(event) {
                    const file = event.target.files[0];
                    if (!file) return;
                    this.readAndUploadImage(file, (url) => {
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
                },
                applyLink() {
                    if (!this._selectedNode) return;
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
                    const el = this.$refs.codeContainer;
                    if (!el) return;
                    if (!this.codeMirror) {
                        this.codeMirror = CodeMirror(el, {
                            value: this.html,
                            mode: 'htmlmixed',
                            lineNumbers: true,
                            lineWrapping: true,
                        });
                        this.codeMirror.on('change', () => { this.html = this.codeMirror.getValue(); });
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
