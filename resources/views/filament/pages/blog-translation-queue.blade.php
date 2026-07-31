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
        const BLOG_EDITOR_HIGHLIGHT_SHADES = ['100', '200', '300'];
        const BLOG_EDITOR_TEXT_SIZES = ['xs', 'sm', 'base', 'lg', 'xl', '2xl', '3xl', '4xl', '5xl'];
        const BLOG_EDITOR_COLOR_CLASS_RE = /^text-(gray|red|orange|yellow|green|blue|indigo|purple)-(50|100|200|300|400|500|600|700|800|900)$/;
        const BLOG_EDITOR_SIZE_CLASS_RE = /^text-(xs|sm|base|lg|xl|2xl|3xl|4xl|5xl)$/;
        const BLOG_EDITOR_BG_CLASS_RE = /^bg-(gray|red|orange|yellow|green|blue|indigo|purple)-(50|100|200|300|400|500|600|700|800|900)$/;
        const BLOG_EDITOR_FONT_FAMILY_RE = /^font-(sans|serif|mono)$/;
        const BLOG_EDITOR_ALIGN_RE = /^text-(left|center|right|justify)$/;
        const BLOG_EDITOR_FORMAT_TAGS = ['p', 'h1', 'h2', 'h3', 'h4', 'blockquote'];

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

                textColors: BLOG_EDITOR_TEXT_COLORS,
                textShades: BLOG_EDITOR_TEXT_SHADES,
                highlightShades: BLOG_EDITOR_HIGHLIGHT_SHADES,
                textSizes: BLOG_EDITOR_TEXT_SIZES,
                formatTags: BLOG_EDITOR_FORMAT_TAGS,

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
                    const doc = '<!doctype html><html><head><meta charset="utf-8">'
                        + '<script src="https://cdn.tailwindcss.com"><' + '/script>'
                        + '<style>body{padding:1.5rem;max-width:48rem;margin:0 auto;outline:none;}'
                        + '[data-blogeditor-selected]{outline:2px solid #6366f1;outline-offset:2px;}</style>'
                        + '<' + '/head><body contenteditable="true">' + this.html + '<' + '/body><' + '/html>';
                    frame.onload = () => {
                        const idoc = frame.contentDocument;
                        idoc.body.addEventListener('input', () => this.syncFromVisual());
                        idoc.body.addEventListener('click', (e) => this.visualClick(e));
                        idoc.addEventListener('selectionchange', () => this.onSelectionChange());
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
                hidePopup() {
                    this.popupVisible = false;
                    this.popupKind = null;
                },
                visualClick(e) {
                    const frame = this.$refs.visualFrame;
                    const body = frame.contentDocument.body;

                    if (e.target === body) { this.clearSelection(); this.hidePopup(); return; }
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
                applyClassToSelection(regex, newClass) {
                    this.restoreSelection();
                    const frame = this.$refs.visualFrame;
                    const idoc = frame.contentDocument;
                    const sel = idoc.getSelection();
                    if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;
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
                    this.setClassByRegex(block, BLOG_EDITOR_ALIGN_RE, newClass);
                    this.syncFromVisual();
                },
                applyTextColorSelection() { this.applyClassToSelection(BLOG_EDITOR_COLOR_CLASS_RE, this.selColor); },
                applyTextSizeSelection() { this.applyClassToSelection(BLOG_EDITOR_SIZE_CLASS_RE, this.selSize); },
                applyHighlightSelection() { this.applyClassToSelection(BLOG_EDITOR_BG_CLASS_RE, this.selBg); },
                applyFontFamilySelection() { this.applyClassToSelection(BLOG_EDITOR_FONT_FAMILY_RE, this.selFontFamily); },
                applyFormatBlockSelect() { this.execCmd('formatBlock', this.selFormatTag); },
                execCmd(cmd, value) {
                    this.restoreSelection();
                    const idoc = this.$refs.visualFrame.contentDocument;
                    idoc.execCommand(cmd, false, value ?? null);
                    this.syncFromVisual();
                },
                toggleBold() { this.execCmd('bold'); },
                toggleItalic() { this.execCmd('italic'); },
                toggleUnderline() { this.execCmd('underline'); },
                insertList(ordered) { this.execCmd(ordered ? 'insertOrderedList' : 'insertUnorderedList'); },
                clearFormatting() {
                    this.execCmd('removeFormat');
                    [BLOG_EDITOR_COLOR_CLASS_RE, BLOG_EDITOR_SIZE_CLASS_RE, BLOG_EDITOR_BG_CLASS_RE, BLOG_EDITOR_FONT_FAMILY_RE].forEach((re) => {
                        this.applyClassToSelection(re, '');
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
                    this.hidePopup();
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
                    this.showElementPopup('link', el);
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
