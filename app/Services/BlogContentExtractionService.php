<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class BlogContentExtractionService
{
    // Same browser-shaped headers as BlogTranslationDetectionService - without them some
    // requests get a different (often bot-blocked) response than a real visitor would.
    private const FETCH_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // The one reliable wrapper around a single article (title, cover, body) - title and body
    // are located *within* this, never by searching the whole document, so an unrelated element
    // elsewhere on the page (a related-posts card, a hidden template, ...) that happens to also
    // carry the "post-body" or "article-title" class can never be picked up by mistake.
    private const CARD_CLASS = 'article-card';

    // Only the article body - the title is captured separately (it needs its own translation
    // handling, not lumped in with the body content) and so is everything outside it.
    private const CONTENT_CLASS = 'post-body';

    private const TITLE_CLASS = 'article-title';

    // (attribute, value, urls column) triples identifying each <meta> tag worth translating for
    // SEO. Excludes meta that isn't actual translatable text - og:url/og:image/twitter:image/
    // twitter:card, canonical, hreflang - those are structural/technical, not content.
    private const META_FIELDS = [
        'meta_description' => ['name', 'description'],
        'meta_keywords' => ['name', 'keywords'],
        'og_title' => ['property', 'og:title'],
        'og_description' => ['property', 'og:description'],
        'twitter_title' => ['name', 'twitter:title'],
        'twitter_description' => ['name', 'twitter:description'],
    ];

    // ── Tailwind conversion engine ──────────────────────────────────────────
    // Ported from smmland/smmplus-extensions (branch claude/festive-cerf-4Jtc3,
    // smmplus-tools/html-to-tailwind.js) - kept behaviourally identical to that reference tool
    // rather than reinvented, since it's the version already reviewed/approved. Maps to
    // Tailwind's actual design-token scale (text-lg, leading-tight, named colors, the 4px
    // spacing scale) instead of raw arbitrary values wherever a token is close enough, and
    // cleans up Google Docs' export artifacts (redundant spans, docs-internal-guid wrappers,
    // pre/pre-wrap noise, styled-paragraph "headings") rather than faithfully preserving them.

    private const PX_SCALE = [
        0 => '0', 1 => 'px', 2 => '0.5', 4 => '1', 6 => '1.5', 8 => '2', 10 => '2.5', 12 => '3', 14 => '3.5',
        16 => '4', 18 => '4.5', 20 => '5', 22 => '5.5', 24 => '6', 28 => '7', 32 => '8', 36 => '9', 40 => '10',
        44 => '11', 48 => '12', 52 => '13', 56 => '14', 60 => '15', 64 => '16', 72 => '18', 80 => '20', 96 => '24',
    ];

    private const HEX_COLOR_MAP = [
        '#000000' => 'black', '#ffffff' => 'white',
        '#f9fafb' => 'gray-50', '#f3f4f6' => 'gray-100', '#e5e7eb' => 'gray-200',
        '#d1d5db' => 'gray-300', '#9ca3af' => 'gray-400', '#6b7280' => 'gray-500',
        '#4b5563' => 'gray-600', '#374151' => 'gray-700', '#1f2937' => 'gray-800', '#111827' => 'gray-900',
        '#fef2f2' => 'red-50', '#fee2e2' => 'red-100', '#fca5a5' => 'red-300',
        '#f87171' => 'red-400', '#ef4444' => 'red-500', '#dc2626' => 'red-600',
        '#b91c1c' => 'red-700', '#cc0000' => 'red-700',
        '#eff6ff' => 'blue-50', '#dbeafe' => 'blue-100', '#93c5fd' => 'blue-300',
        '#60a5fa' => 'blue-400', '#3b82f6' => 'blue-500', '#2563eb' => 'blue-600',
        '#1d4ed8' => 'blue-700', '#1e40af' => 'blue-800', '#1155cc' => 'blue-700',
        '#0000cc' => 'blue-800', '#0d47a1' => 'blue-900',
        '#f0fdf4' => 'green-50', '#dcfce7' => 'green-100', '#86efac' => 'green-300',
        '#4ade80' => 'green-400', '#22c55e' => 'green-500', '#16a34a' => 'green-600',
        '#15803d' => 'green-700', '#2e7d32' => 'green-800',
        '#fefce8' => 'yellow-50', '#fef9c3' => 'yellow-100', '#fde047' => 'yellow-300',
        '#facc15' => 'yellow-400', '#eab308' => 'yellow-500',
        '#fff7ed' => 'orange-50', '#fed7aa' => 'orange-100', '#fb923c' => 'orange-400',
        '#f97316' => 'orange-500', '#ea580c' => 'orange-600',
        '#fdf4ff' => 'purple-50', '#f3e8ff' => 'purple-100', '#c084fc' => 'purple-400',
        '#a855f7' => 'purple-500', '#9333ea' => 'purple-600', '#7c3aed' => 'purple-700',
        '#6a1b9a' => 'purple-900', '#4a148c' => 'purple-950',
        '#f0f4ff' => 'indigo-50', '#e0e7ff' => 'indigo-100', '#818cf8' => 'indigo-400',
        '#6366f1' => 'indigo-500', '#4f46e5' => 'indigo-600', '#4338ca' => 'indigo-700',
    ];

    // Classes representing GDocs/browser defaults - suppressed so they don't clutter output.
    private const GDOCS_DEFAULT_CLASSES = ['font-sans', 'text-base', 'text-black'];

    private int $stylesConvertedCount = 0;

    /**
     * Fetches a URL's live page (any language - not just English) and pulls out, as separate
     * pieces:
     *  - the article body only (".post-body", found *inside* ".article-card") - images
     *    downloaded and inline-styles converted to Tailwind classes - saved as a file, one per
     *    language ("content-{lang}.html");
     *  - the on-page article title (".article-title", also scoped inside ".article-card") and
     *    the SEO-relevant <meta> text (title tag, description, keywords, OG/Twitter
     *    title+description - the fields Google actually shows or reads per language, not
     *    structural tags like canonical/hreflang/og:image) - saved directly on the row, not as
     *    a file, so they're editable/queryable per URL.
     *
     * @return array{ok: bool, error?: string, imagesDownloaded?: int, imagesInlined?: int, stylesConverted?: int, contentPath?: string, previewUrl?: string, contentUrl?: string, articleTitle?: ?string}
     */
    public function extract(Url $row): array
    {
        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(20)->get($row->source_url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Fetch error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'HTTP '.$response->status()];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$response->body());
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $card = $this->firstElementByClass($xpath, self::CARD_CLASS);

        if (! $card) {
            return ['ok' => false, 'error' => 'Could not find the "'.self::CARD_CLASS.'" wrapper on the page.'];
        }

        $container = $this->firstElementByClass($xpath, self::CONTENT_CLASS, $card);

        if (! $container) {
            return ['ok' => false, 'error' => 'Could not find a "'.self::CONTENT_CLASS.'" element inside the article card.'];
        }

        $titleNode = $this->firstElementByClass($xpath, self::TITLE_CLASS, $card);
        $articleTitle = $titleNode ? $this->normalizeWhitespace($titleNode->textContent) : null;

        $seo = $this->extractSeoMeta($xpath);
        $seo['article_title'] = $articleTitle;

        $slug = $row->slug ?: 'untitled';
        $lang = $row->lang ?: 'en';
        $baseDir = "blog/{$slug}";

        $imageStats = $this->processImages($container, $doc, $baseDir);
        $stylesConverted = $this->convertStyles($xpath, $container);

        // Belt-and-suspenders: if the body's first block happens to just repeat the title
        // verbatim (a duplicate accidentally left in the source content), drop it - it isn't
        // reachable via the class-scoped lookups above, but source content itself can still
        // contain a stray duplicate.
        $this->stripLeadingDuplicateTitle($container, $articleTitle);

        $this->polishPresentation($xpath, $container);

        $contentHtml = $this->innerHtml($container);

        Storage::disk('public')->put("{$baseDir}/content-{$lang}.html", $contentHtml);

        $previewTitle = e($articleTitle ?? $row->slug);
        $dir = Language::direction($lang);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="{$lang}" dir="{$dir}">
            <head>
            <meta charset="utf-8">
            <title>{$previewTitle} - preview</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="mx-auto max-w-3xl px-6 py-10" dir="{$dir}">
            <h1 class="text-3xl font-bold mb-6">{$previewTitle}</h1>
            {$contentHtml}
            </body>
            </html>
            HTML;

        Storage::disk('public')->put("{$baseDir}/preview-{$lang}.html", $previewHtml);

        $contentPath = "{$baseDir}/content-{$lang}.html";

        $row->content_extracted_at = now();
        $row->content_extraction_path = $contentPath;
        $row->article_title = $articleTitle;
        $row->seo_title = $seo['seo_title'];
        $row->meta_description = $seo['meta_description'];
        $row->meta_keywords = $seo['meta_keywords'];
        $row->og_title = $seo['og_title'];
        $row->og_description = $seo['og_description'];
        $row->twitter_title = $seo['twitter_title'];
        $row->twitter_description = $seo['twitter_description'];
        $row->save();

        return [
            'ok' => true,
            'imagesDownloaded' => $imageStats['downloaded'],
            'imagesInlined' => $imageStats['inlined'],
            'stylesConverted' => $stylesConverted,
            'contentPath' => $contentPath,
            'contentUrl' => $this->assetUrl($contentPath),
            'previewUrl' => $this->assetUrl("{$baseDir}/preview-{$lang}.html"),
            'articleTitle' => $articleTitle,
        ];
    }

    private function firstElementByClass(\DOMXPath $xpath, string $class, ?\DOMElement $context = null): ?\DOMElement
    {
        $query = ($context ? '.' : '')."//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
        $nodes = $context ? $xpath->query($query, $context) : $xpath->query($query);

        return ($nodes && $nodes->length > 0) ? $nodes->item(0) : null;
    }

    /**
     * @return array<string, ?string>
     */
    private function extractSeoMeta(\DOMXPath $xpath): array
    {
        $titleNodes = $xpath->query('//title');
        $meta = [
            'seo_title' => $titleNodes->length > 0 ? $this->normalizeWhitespace($titleNodes->item(0)->textContent) : null,
        ];

        foreach (self::META_FIELDS as $column => [$attr, $value]) {
            $nodes = $xpath->query("//meta[@{$attr}='{$value}']/@content");
            $meta[$column] = $nodes->length > 0 ? $this->normalizeWhitespace($nodes->item(0)->nodeValue) : null;
        }

        return $meta;
    }

    private function stripLeadingDuplicateTitle(\DOMElement $container, ?string $articleTitle): void
    {
        if ($articleTitle === null) {
            return;
        }

        foreach ($container->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }

            if ($this->normalizeWhitespace($child->textContent) === $articleTitle) {
                $container->removeChild($child);
            }

            // Only the first element child is a plausible accidental duplicate - stop either way.
            break;
        }
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
    }

    /**
     * Presentation touches that go beyond the style->class conversion itself: round the corners
     * of every content image, and make sure tables read as an actual table (full width,
     * collapsed borders, bordered/padded cells) and scroll horizontally instead of overflowing
     * on narrow screens, without doubling up classes a table already has from its own inline
     * styles.
     */
    private function polishPresentation(\DOMXPath $xpath, \DOMElement $container): void
    {
        foreach ($xpath->query('.//img', $container) as $img) {
            $class = trim($img->getAttribute('class'));

            if (! preg_match('/(^|\s)rounded(-\S+)?(\s|$)/', $class)) {
                $img->setAttribute('class', trim($class.' rounded-lg'));
            }
        }

        foreach (iterator_to_array($xpath->query('.//table', $container)) as $table) {
            $class = trim($table->getAttribute('class'));
            $additions = [];

            // Only default to w-full if there's no width utility at all yet - a table whose
            // inline width style already converted to e.g. w-full (see convertSize()) shouldn't
            // also get a second, redundant w-full added.
            if (! preg_match('/(^|\s)w-\S+(\s|$)/', $class)) {
                $additions[] = 'w-full';
            }

            if (! str_contains($class, 'border-collapse')) {
                $additions[] = 'border-collapse';
            }

            if ($additions !== []) {
                $table->setAttribute('class', trim($class.' '.implode(' ', $additions)));
            }

            foreach ($xpath->query('.//td | .//th', $table) as $cell) {
                $cellClass = trim($cell->getAttribute('class'));

                if (! str_contains($cellClass, 'border')) {
                    $cellClass = trim($cellClass.' border border-gray-200');
                }

                if (! preg_match('/(^|\s)p-\S+(\s|$)/', $cellClass)) {
                    $cellClass = trim($cellClass.' p-2');
                }

                $cell->setAttribute('class', $cellClass);
            }

            // Wrap in a horizontally-scrollable div so a wide table can't blow out the layout
            // on narrow screens - unless it's already wrapped in one (re-running extraction).
            $parent = $table->parentNode;
            $alreadyWrapped = $parent instanceof \DOMElement
                && str_contains($parent->getAttribute('class'), 'overflow-x-auto');

            if (! $alreadyWrapped) {
                $wrapper = $table->ownerDocument->createElement('div');
                $wrapper->setAttribute('class', 'overflow-x-auto');
                $parent->replaceChild($wrapper, $table);
                $wrapper->appendChild($table);
            }
        }
    }

    /**
     * @return array{downloaded: int, inlined: int}
     */
    private function processImages(\DOMElement $container, \DOMDocument $doc, string $baseDir): array
    {
        $downloaded = 0;
        $inlined = 0;

        $xpath = new \DOMXPath($doc);

        foreach ($xpath->query('.//img', $container) as $img) {
            $src = $img->getAttribute('src');

            if ($src === '') {
                continue;
            }

            if (str_starts_with($src, 'data:image/')) {
                $link = $this->saveBase64Image($src, $baseDir);

                if ($link !== null) {
                    $img->setAttribute('src', $link);
                    $downloaded++;
                    $inlined++;
                }

                continue;
            }

            // Not base64 - keep the src pointing at the original, just also save a local copy.
            if ($this->downloadImage($src, $baseDir) !== null) {
                $downloaded++;
            }
        }

        foreach ($xpath->query(".//*[contains(@style, 'background-image')]", $container) as $el) {
            $style = $el->getAttribute('style');

            if (! preg_match('/background-image\s*:\s*url\((["\']?)([^"\')]+)\1\)/i', $style, $m)) {
                continue;
            }

            $url = $m[2];

            if (str_starts_with($url, 'data:image/')) {
                $link = $this->saveBase64Image($url, $baseDir);

                if ($link !== null) {
                    $newStyle = str_replace($m[0], "background-image:url('{$link}')", $style);
                    $el->setAttribute('style', $newStyle);
                    $downloaded++;
                    $inlined++;
                }

                continue;
            }

            if ($this->downloadImage($url, $baseDir) !== null) {
                $downloaded++;
            }
        }

        return ['downloaded' => $downloaded, 'inlined' => $inlined];
    }

    private function saveBase64Image(string $dataUri, string $baseDir): ?string
    {
        if (! preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUri, $m)) {
            return null;
        }

        $bytes = base64_decode($m[2], true);

        if ($bytes === false) {
            return null;
        }

        $ext = $this->normalizeExtension($m[1]);
        $filename = substr(md5($m[2]), 0, 16).'.'.$ext;
        $path = "{$baseDir}/images/{$filename}";

        Storage::disk('public')->put($path, $bytes);

        return $this->assetUrl($path);
    }

    private function downloadImage(string $url, string $baseDir): ?string
    {
        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(15)->get($url);
        } catch (\Throwable) {
            // A single unreachable/erroring image (dead link, blocked host, timeout, malformed
            // response, ...) shouldn't abort extracting the rest of the page - just skip it and
            // leave that one reference as the original external URL.
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $contentType = $response->header('Content-Type');
        $ext = $contentType
            ? $this->normalizeExtension(str_replace('image/', '', explode(';', $contentType)[0]))
            : (pathinfo(parse_url($url, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg');

        $filename = substr(md5($url), 0, 16).'.'.$ext;
        $path = "{$baseDir}/images/{$filename}";

        Storage::disk('public')->put($path, $response->body());

        return $this->assetUrl($path);
    }

    private function normalizeExtension(string $subtype): string
    {
        return match (strtolower($subtype)) {
            'jpeg' => 'jpg',
            'svg+xml' => 'svg',
            'x-icon' => 'ico',
            default => preg_replace('/[^a-z0-9]/i', '', strtolower($subtype)) ?: 'jpg',
        };
    }

    private function assetUrl(string $relativePath): string
    {
        return url('/blog-content/'.$relativePath);
    }

    // ── Conversion orchestration ────────────────────────────────────────────

    private function convertStyles(\DOMXPath $xpath, \DOMElement $container): int
    {
        $this->stylesConvertedCount = 0;

        foreach (iterator_to_array($container->childNodes) as $child) {
            $this->processElement($child);
        }

        $this->unwrapRedundantSpans($xpath, $container);
        $this->liftHeadingSpans($xpath, $container);
        $this->promoteStyledParagraphsToHeadings($xpath, $container);
        $this->removeEmptyParagraphs($xpath, $container);
        $this->unwrapTableWrappers($xpath, $container);

        return $this->stylesConvertedCount;
    }

    private function processElement(\DOMNode $node): void
    {
        if ($node->nodeType !== XML_ELEMENT_NODE) {
            return;
        }

        /** @var \DOMElement $el */
        $el = $node;
        $tag = strtoupper($el->tagName);

        // Unwrap Google Docs' docs-internal-guid wrapper <b> before removing its id.
        if ($tag === 'B' && $el->hasAttribute('id') && str_starts_with($el->getAttribute('id'), 'docs-internal-guid')) {
            $parent = $el->parentNode;
            while ($el->firstChild) {
                $parent->insertBefore($el->firstChild, $el);
            }
            $parent->removeChild($el);

            return;
        }

        $el->removeAttribute('dir');
        $el->removeAttribute('role');
        $el->removeAttribute('aria-level');
        $el->removeAttribute('data-alt-editor-id');

        if ($el->hasAttribute('id') && str_starts_with($el->getAttribute('id'), 'docs-internal-guid')) {
            $el->removeAttribute('id');
        }

        if ($tag === 'COLGROUP') {
            $el->parentNode->removeChild($el);

            return;
        }

        if ($tag === 'DIV' || $tag === 'TABLE') {
            $el->removeAttribute('align');
        }

        // Unwrap GDocs image-wrapper spans: <span style="..."><img></span>
        if ($tag === 'SPAN') {
            $meaningfulChildren = [];

            foreach (iterator_to_array($el->childNodes) as $child) {
                if ($child->nodeType === XML_ELEMENT_NODE
                    || ($child->nodeType === XML_TEXT_NODE && trim($child->textContent) !== '')) {
                    $meaningfulChildren[] = $child;
                }
            }

            if (count($meaningfulChildren) === 1
                && $meaningfulChildren[0]->nodeType === XML_ELEMENT_NODE
                && strtoupper($meaningfulChildren[0]->tagName) === 'IMG') {
                $img = $meaningfulChildren[0];
                $el->parentNode->insertBefore($img, $el);
                $el->parentNode->removeChild($el);
                $this->processElement($img);

                return;
            }
        }

        if ($el->hasAttribute('style')) {
            $styles = $this->parseStyleString($el->getAttribute('style'));
            $classes = $this->stylesToClasses($styles, strtolower($el->tagName));

            $existing = trim($el->getAttribute('class'));
            $existingClasses = $existing === '' ? [] : preg_split('/\s+/', $existing);
            $allClasses = array_values(array_unique(array_merge($existingClasses, $classes)));

            if ($allClasses !== []) {
                $el->setAttribute('class', implode(' ', $allClasses));
            }

            $el->removeAttribute('style');
            $this->stylesConvertedCount++;
        }

        foreach (iterator_to_array($el->childNodes) as $child) {
            $this->processElement($child);
        }
    }

    private function unwrapRedundantSpans(\DOMXPath $xpath, \DOMElement $container): void
    {
        foreach (iterator_to_array($xpath->query('.//span', $container)) as $span) {
            $hasClass = $span->hasAttribute('class') && trim($span->getAttribute('class')) !== '';
            $hasId = $span->hasAttribute('id');
            $hasOther = false;

            foreach (iterator_to_array($span->attributes) as $attr) {
                if ($attr->name !== 'class' && $attr->name !== 'style') {
                    $hasOther = true;
                    break;
                }
            }

            if (! $hasClass && ! $hasId && ! $hasOther) {
                $parent = $span->parentNode;

                if ($parent) {
                    while ($span->firstChild) {
                        $parent->insertBefore($span->firstChild, $span);
                    }
                    $parent->removeChild($span);
                }
            }
        }
    }

    private function unwrapTableWrappers(\DOMXPath $xpath, \DOMElement $container): void
    {
        foreach (iterator_to_array($xpath->query('.//table', $container)) as $table) {
            $wrapper = $table->parentNode;

            if (! $wrapper instanceof \DOMElement || strtoupper($wrapper->tagName) !== 'DIV') {
                continue;
            }

            $hasOther = false;
            foreach (iterator_to_array($wrapper->attributes) as $attr) {
                if ($attr->name !== 'class') {
                    $hasOther = true;
                    break;
                }
            }

            if ($hasOther) {
                continue;
            }

            $classes = array_values(array_filter(preg_split('/\s+/', trim($wrapper->getAttribute('class')))));
            $meaningless = ['ml-0'];
            $allMeaningless = true;

            foreach ($classes as $c) {
                if (! in_array($c, $meaningless, true)) {
                    $allMeaningless = false;
                    break;
                }
            }

            if ($allMeaningless) {
                $parent = $wrapper->parentNode;
                while ($wrapper->firstChild) {
                    $parent->insertBefore($wrapper->firstChild, $wrapper);
                }
                $parent->removeChild($wrapper);
            }
        }
    }

    private function removeEmptyParagraphs(\DOMXPath $xpath, \DOMElement $container): void
    {
        foreach (iterator_to_array($xpath->query('.//p', $container)) as $p) {
            $text = trim($p->textContent);
            $hasImg = $xpath->query('.//img', $p)->length > 0;

            if ($text === '' && ! $hasImg) {
                $p->parentNode->removeChild($p);
            }
        }
    }

    // Google Docs sometimes exports headings as styled paragraphs (<p><span class="text-xl
    // font-bold">…</span></p>) instead of real <h2>/<h3> tags - promote them back.
    private function promoteStyledParagraphsToHeadings(\DOMXPath $xpath, \DOMElement $container): void
    {
        foreach (iterator_to_array($xpath->query('.//p', $container)) as $p) {
            $childEls = [];
            foreach (iterator_to_array($p->childNodes) as $c) {
                if ($c->nodeType === XML_ELEMENT_NODE) {
                    $childEls[] = $c;
                }
            }

            if (count($childEls) !== 1 || strtoupper($childEls[0]->tagName) !== 'SPAN') {
                continue;
            }

            $span = $childEls[0];
            $hasExternalText = false;

            foreach (iterator_to_array($p->childNodes) as $n) {
                if ($n->nodeType === XML_TEXT_NODE && trim($n->textContent) !== '') {
                    $hasExternalText = true;
                    break;
                }
            }

            if ($hasExternalText) {
                continue;
            }

            $spanClasses = array_values(array_filter(preg_split('/\s+/', trim($span->getAttribute('class')))));
            $pClasses = array_values(array_filter(preg_split('/\s+/', trim($p->getAttribute('class')))));

            $tag = null;
            $defaultClasses = [];

            if (in_array('text-xl', $spanClasses, true) && in_array('font-bold', $spanClasses, true)) {
                $tag = 'h2';
                $defaultClasses = ['mt-6', 'mb-2'];
            } elseif (in_array('text-lg', $spanClasses, true) && in_array('font-bold', $spanClasses, true)) {
                $tag = 'h3';
                $defaultClasses = ['mt-5', 'mb-1'];
            }

            if ($tag === null) {
                continue;
            }

            $heading = $p->ownerDocument->createElement($tag);
            $merged = array_values(array_unique(array_merge($pClasses, $spanClasses, $defaultClasses)));

            if ($merged !== []) {
                $heading->setAttribute('class', implode(' ', $merged));
            }

            while ($span->firstChild) {
                $heading->appendChild($span->firstChild);
            }

            $p->parentNode->replaceChild($heading, $p);
        }
    }

    private function liftHeadingSpans(\DOMXPath $xpath, \DOMElement $container): void
    {
        $query = './/h1/span | .//h2/span | .//h3/span | .//h4/span | .//h5/span | .//h6/span';

        foreach (iterator_to_array($xpath->query($query, $container)) as $span) {
            $heading = $span->parentNode;
            $spanClasses = array_values(array_filter(preg_split('/\s+/', trim($span->getAttribute('class')))));
            $headingClasses = array_values(array_filter(preg_split('/\s+/', trim($heading->getAttribute('class')))));
            $merged = array_values(array_unique(array_merge($headingClasses, $spanClasses)));

            if ($merged !== []) {
                $heading->setAttribute('class', implode(' ', $merged));
            } else {
                $heading->removeAttribute('class');
            }

            while ($span->firstChild) {
                $heading->insertBefore($span->firstChild, $span);
            }
            $heading->removeChild($span);
        }
    }

    private function parseStyleString(string $styleStr): array
    {
        $styles = [];

        foreach (explode(';', $styleStr) as $rule) {
            $idx = strpos($rule, ':');

            if ($idx === false) {
                continue;
            }

            $prop = strtolower(trim(substr($rule, 0, $idx)));
            $val = trim(substr($rule, $idx + 1));

            if ($prop !== '' && $val !== '') {
                $styles[$prop] = $val;
            }
        }

        return $styles;
    }

    /**
     * @return list<string>
     */
    private function stylesToClasses(array $styles, string $tag): array
    {
        $classes = [];

        $add = function (?string ...$cls) use (&$classes): void {
            foreach ($cls as $c) {
                if ($c === null || $c === '') {
                    continue;
                }

                foreach (preg_split('/\s+/', trim($c)) as $token) {
                    if ($token === '' || in_array($token, self::GDOCS_DEFAULT_CLASSES, true)) {
                        continue;
                    }

                    if (! in_array($token, $classes, true)) {
                        $classes[] = $token;
                    }
                }
            }
        };

        foreach ($styles as $prop => $val) {
            if ($prop === 'height' && in_array($val, ['0', '0pt', '0px'], true)) {
                continue;
            }

            switch ($prop) {
                case 'font-size': $add($this->convertFontSize($val)); break;
                case 'font-weight': $add($this->convertFontWeight($val)); break;
                case 'font-style': if ($val === 'italic') { $add('italic'); } break;
                case 'font-family': $add($this->convertFontFamily($val)); break;
                case 'font-variant': break;
                case 'color': $add($this->convertColor($val)); break;
                case 'background-color': $add($this->convertBgColor($val)); break;
                case 'line-height': $add($this->convertLineHeight($val)); break;
                case 'text-decoration': $add($this->convertTextDecoration($val)); break;
                case 'text-decoration-skip-ink':
                case '-webkit-text-decoration-skip': break;
                case 'text-align': $add($this->convertTextAlign($val)); break;
                case 'white-space': $add($this->convertWhiteSpace($val)); break;
                case 'vertical-align': $add($this->convertVerticalAlign($val)); break;
                case 'display': $add($this->convertDisplay($val)); break;
                case 'overflow': $add($this->convertOverflow($val)); break;
                case 'overflow-x': $add($this->convertOverflow($val, 'x')); break;
                case 'overflow-y': $add($this->convertOverflow($val, 'y')); break;
                case 'overflow-wrap': $add($this->convertOverflowWrap($val)); break;
                case 'border':
                    // GDocs sets border:0 on <table> as a reset - skip it since cells carry
                    // their own borders.
                    if ($tag === 'table' && in_array($val, ['0', 'none', '0px', '0pt'], true)) {
                        break;
                    }
                    $add($this->convertBorder($val));
                    break;
                case 'border-top': $add($this->convertBorderSide('t', $val)); break;
                case 'border-right': $add($this->convertBorderSide('r', $val)); break;
                case 'border-bottom': $add($this->convertBorderSide('b', $val)); break;
                case 'border-left': $add($this->convertBorderSide('l', $val)); break;
                case 'border-collapse': $add($this->convertBorderCollapse($val)); break;
                case 'table-layout': $add($this->convertTableLayout($val)); break;
                case 'list-style-type': $add($this->convertListStyleType($val)); break;
                case 'object-fit': $add($this->convertObjectFit($val)); break;
                case 'margin': $add(...$this->convertSpacingShorthand('m', $val)); break;
                case 'margin-top': $add($this->convertSpacing('mt', $val)); break;
                case 'margin-bottom': $add($this->convertSpacing('mb', $val)); break;
                case 'margin-left': $add($this->convertSpacing('ml', $val)); break;
                case 'margin-right': $add($this->convertSpacing('mr', $val)); break;
                case 'padding': $add(...$this->convertSpacingShorthand('p', $val)); break;
                case 'padding-top': $add($this->convertSpacing('pt', $val)); break;
                case 'padding-bottom': $add($this->convertSpacing('pb', $val)); break;
                case 'padding-left': $add($this->convertSpacing('pl', $val)); break;
                case 'padding-right': $add($this->convertSpacing('pr', $val)); break;
                case 'padding-inline-start': $add($this->convertSpacing('pl', $val)); break;
                case 'padding-inline-end': $add($this->convertSpacing('pr', $val)); break;
                case 'width': $add($this->convertSize('w', $val)); break;
                case 'height': $add($this->convertSize('h', $val)); break;
                case 'max-width': $add($this->convertSize('max-w', $val)); break;
                case 'min-width': $add($this->convertSize('min-w', $val)); break;
                case 'max-height': $add($this->convertSize('max-h', $val)); break;
                case 'min-height': $add($this->convertSize('min-h', $val)); break;
                default: break;
            }
        }

        // If all 4 sides have a border, consolidate to just 'border'.
        $sides = ['border-t', 'border-r', 'border-b', 'border-l'];
        if (count(array_intersect($sides, $classes)) === 4) {
            $classes = array_values(array_diff($classes, $sides));
            $classes[] = 'border';
        }

        // Consolidate pt-X pr-X pb-X pl-X -> p-X when all four values match.
        $pPrefixes = ['pt', 'pr', 'pb', 'pl'];
        $pClasses = [];
        foreach ($pPrefixes as $pfx) {
            foreach ($classes as $c) {
                if (str_starts_with($c, "{$pfx}-")) {
                    $pClasses[$pfx] = $c;
                    break;
                }
            }
        }
        if (count($pClasses) === 4) {
            $pVals = array_map(fn ($c) => substr($c, strpos($c, '-') + 1), array_values($pClasses));
            if (count(array_unique($pVals)) === 1) {
                $classes = array_values(array_diff($classes, array_values($pClasses)));
                $classes[] = "p-{$pVals[0]}";
            }
        }

        return $classes;
    }

    private function ptToPx(float $pt): int
    {
        return (int) round($pt * 1.3333);
    }

    private function convertSpacing(string $prefix, string $val): string
    {
        $val = trim($val);

        if ($val === '' || $val === 'auto') {
            return "{$prefix}-auto";
        }
        if (preg_match('/^0(px|pt|em|rem|%)?$/', $val)) {
            return "{$prefix}-0";
        }

        $px = null;
        if (preg_match('/^(-?[\d.]+)px$/', $val, $m)) {
            $px = (float) $m[1];
        } elseif (preg_match('/^(-?[\d.]+)pt$/', $val, $m)) {
            $px = $this->ptToPx((float) $m[1]);
        } elseif (preg_match('/^(-?[\d.]+)rem$/', $val, $m)) {
            $px = (float) $m[1] * 16;
        } elseif (preg_match('/^(-?[\d.]+)em$/', $val, $m)) {
            $px = (float) $m[1] * 16;
        }

        if ($px !== null) {
            $neg = $px < 0 ? '-' : '';
            $abs = abs($px);

            if (floor($abs) == $abs && isset(self::PX_SCALE[(int) $abs])) {
                return "{$neg}{$prefix}-".self::PX_SCALE[(int) $abs];
            }

            $keys = array_map('intval', array_keys(self::PX_SCALE));
            sort($keys);
            $closest = $keys[0];
            foreach ($keys as $k) {
                if (abs($k - $abs) < abs($closest - $abs)) {
                    $closest = $k;
                }
            }

            if (abs($closest - $abs) <= 2) {
                return "{$neg}{$prefix}-".self::PX_SCALE[$closest];
            }

            return "{$neg}{$prefix}-[{$val}]";
        }

        return "{$prefix}-[{$val}]";
    }

    /**
     * @return list<string>
     */
    private function convertSpacingShorthand(string $prefix, string $val): array
    {
        $parts = preg_split('/\s+/', trim($val));

        if (count($parts) === 1) {
            return [$this->convertSpacing($prefix, $parts[0])];
        }

        if (count($parts) === 4) {
            return [
                $this->convertSpacing("{$prefix}t", $parts[0]),
                $this->convertSpacing("{$prefix}r", $parts[1]),
                $this->convertSpacing("{$prefix}b", $parts[2]),
                $this->convertSpacing("{$prefix}l", $parts[3]),
            ];
        }

        if (count($parts) === 2) {
            return [
                $this->convertSpacing("{$prefix}y", $parts[0]),
                $this->convertSpacing("{$prefix}x", $parts[1]),
            ];
        }

        return ["{$prefix}-[{$val}]"];
    }

    private function convertFontSize(string $val): ?string
    {
        $val = trim($val);
        $px = null;

        if (preg_match('/^([\d.]+)px$/', $val, $m)) {
            $px = (float) $m[1];
        } elseif (preg_match('/^([\d.]+)pt$/', $val, $m)) {
            $px = $this->ptToPx((float) $m[1]);
        } elseif (preg_match('/^([\d.]+)rem$/', $val, $m)) {
            $px = (float) $m[1] * 16;
        }

        if ($px === null) {
            return null;
        }

        return match (true) {
            $px <= 11 => 'text-xs',
            $px <= 14 => 'text-sm',
            $px <= 16 => 'text-base',
            $px <= 20 => 'text-lg',
            $px <= 24 => 'text-xl',
            $px <= 30 => 'text-2xl',
            $px <= 38 => 'text-3xl',
            $px <= 48 => 'text-4xl',
            default => 'text-5xl',
        };
    }

    private function convertFontWeight(string $val): ?string
    {
        $map = [
            '100' => 'font-thin', '200' => 'font-extralight', '300' => 'font-light',
            '400' => null, '500' => 'font-medium', '600' => 'font-semibold', '700' => 'font-bold',
            '800' => 'font-extrabold', '900' => 'font-black',
            'thin' => 'font-thin', 'light' => 'font-light', 'normal' => null, 'medium' => 'font-medium',
            'semibold' => 'font-semibold', 'bold' => 'font-bold', 'extrabold' => 'font-extrabold',
        ];

        return $map[trim($val)] ?? null;
    }

    private function convertFontFamily(string $val): ?string
    {
        $v = strtolower($val);

        if (str_contains($v, 'mono') || str_contains($v, 'courier') || str_contains($v, 'consolas')) {
            return 'font-mono';
        }
        if (str_contains($v, 'serif') && ! str_contains($v, 'sans')) {
            return 'font-serif';
        }
        if (str_contains($v, 'sans') || str_contains($v, 'arial') || str_contains($v, 'helvetica') || str_contains($v, 'system')) {
            return 'font-sans';
        }

        return null;
    }

    private function convertColor(string $val): ?string
    {
        $val = strtolower(trim($val));

        if ($val === '' || $val === 'transparent' || $val === 'inherit' || $val === 'currentcolor') {
            return null;
        }
        if (isset(self::HEX_COLOR_MAP[$val])) {
            return 'text-'.self::HEX_COLOR_MAP[$val];
        }
        if (preg_match('/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/', $val, $m)) {
            $hex = '#'.sprintf('%02x%02x%02x', (int) $m[1], (int) $m[2], (int) $m[3]);
            if (isset(self::HEX_COLOR_MAP[$hex])) {
                return 'text-'.self::HEX_COLOR_MAP[$hex];
            }
        }

        return "text-[{$val}]";
    }

    private function convertBgColor(string $val): ?string
    {
        $val = strtolower(trim($val));

        if ($val === '' || $val === 'transparent' || $val === 'inherit') {
            return null;
        }
        if ($val === '#ffffff' || $val === 'white') {
            return null;
        }
        if (isset(self::HEX_COLOR_MAP[$val])) {
            return 'bg-'.self::HEX_COLOR_MAP[$val];
        }

        return "bg-[{$val}]";
    }

    private function convertLineHeight(string $val): ?string
    {
        $val = trim($val);

        if (! is_numeric($val)) {
            return null;
        }

        $n = (float) $val;

        return match (true) {
            $n <= 1.0 => 'leading-none',
            $n <= 1.25 => 'leading-tight',
            $n <= 1.375 => 'leading-snug',
            $n <= 1.5 => 'leading-normal',
            $n <= 1.625 => 'leading-relaxed',
            default => 'leading-loose',
        };
    }

    private function convertTextDecoration(string $val): ?string
    {
        if ($val === '' || $val === 'none') {
            return null;
        }
        if (str_contains($val, 'underline')) {
            return 'underline';
        }
        if (str_contains($val, 'line-through')) {
            return 'line-through';
        }
        if (str_contains($val, 'overline')) {
            return 'overline';
        }

        return null;
    }

    private function convertTextAlign(string $val): ?string
    {
        $map = ['left' => 'text-left', 'center' => 'text-center', 'right' => 'text-right', 'justify' => 'text-justify', 'start' => 'text-left', 'end' => 'text-right'];

        return $map[trim($val)] ?? null;
    }

    private function convertWhiteSpace(string $val): ?string
    {
        $val = trim($val);

        // pre and pre-wrap are GDocs formatting artifacts - skip them entirely.
        if (in_array($val, ['pre', 'pre-wrap', 'break-spaces'], true)) {
            return null;
        }

        $map = ['normal' => null, 'nowrap' => 'whitespace-nowrap', 'pre-line' => 'whitespace-pre-line'];

        return $map[$val] ?? null;
    }

    private function convertVerticalAlign(string $val): ?string
    {
        $map = [
            'baseline' => null, 'top' => 'align-top', 'middle' => 'align-middle',
            'bottom' => 'align-bottom', 'text-top' => 'align-text-top', 'text-bottom' => 'align-text-bottom',
            'sub' => 'align-sub', 'super' => 'align-super',
        ];

        return $map[trim($val)] ?? null;
    }

    private function convertDisplay(string $val): ?string
    {
        $map = [
            'block' => 'block', 'inline' => 'inline', 'inline-block' => 'inline-block',
            'flex' => 'flex', 'inline-flex' => 'inline-flex', 'grid' => 'grid', 'inline-grid' => 'inline-grid',
            'none' => 'hidden', 'table' => 'table', 'table-cell' => 'table-cell', 'table-row' => 'table-row',
        ];

        return $map[trim($val)] ?? null;
    }

    private function convertOverflow(string $val, string $axis = ''): ?string
    {
        $suffix = $axis !== '' ? "-{$axis}" : '';
        $map = [
            'hidden' => "overflow{$suffix}-hidden", 'auto' => "overflow{$suffix}-auto",
            'scroll' => "overflow{$suffix}-scroll", 'visible' => "overflow{$suffix}-visible",
        ];

        return $map[trim($val)] ?? null;
    }

    private function convertOverflowWrap(string $val): ?string
    {
        if ($val === 'break-word') {
            return 'break-words';
        }
        if ($val === 'anywhere') {
            return 'break-all';
        }

        return null;
    }

    private function convertBorder(string $val): string
    {
        if ($val === '' || $val === 'none' || $val === '0') {
            return 'border-0';
        }

        if (preg_match('/(solid|dashed|dotted|double)\s+(#[\da-fA-F]+|\w+)\s+([\d.]+)(px|pt)/', $val, $m)) {
            $style = $m[1];
            $color = strtolower($m[2]);
            $twColor = self::HEX_COLOR_MAP[$color] ?? $color;
            $classes = ['border'];
            if ($style !== 'solid') {
                $classes[] = "border-{$style}";
            }
            if ($twColor !== 'black') {
                $classes[] = "border-{$twColor}";
            }

            return implode(' ', $classes);
        }

        return 'border';
    }

    private function convertBorderSide(string $side, string $val): string
    {
        $prefix = "border-{$side}";

        if ($val === '' || $val === 'none' || $val === '0') {
            return "{$prefix}-0";
        }

        if (preg_match('/(solid|dashed|dotted|double)\s+(#[\da-fA-F]+|\w+)\s+([\d.]+)(px|pt)/', $val, $m)) {
            $color = strtolower($m[2]);
            $twColor = self::HEX_COLOR_MAP[$color] ?? $color;
            $classes = [$prefix];
            if ($twColor !== 'black') {
                $classes[] = "{$prefix}-{$twColor}";
            }

            return implode(' ', $classes);
        }

        return $prefix;
    }

    private function convertBorderCollapse(string $val): ?string
    {
        if ($val === 'collapse') {
            return 'border-collapse';
        }
        if ($val === 'separate') {
            return 'border-separate';
        }

        return null;
    }

    private function convertTableLayout(string $val): ?string
    {
        if ($val === 'fixed') {
            return 'table-fixed';
        }
        if ($val === 'auto') {
            return 'table-auto';
        }

        return null;
    }

    private function convertListStyleType(string $val): ?string
    {
        $map = ['disc' => 'list-disc', 'decimal' => 'list-decimal', 'none' => 'list-none', 'circle' => 'list-[circle]', 'square' => 'list-[square]'];

        return $map[trim($val)] ?? null;
    }

    private function convertSize(string $prefix, string $val): string
    {
        $val = trim($val);

        // Table/block widths specified in pt are GDocs artifacts - treat as full width.
        if ($prefix === 'w' && preg_match('/^[\d.]+pt$/', $val)) {
            return 'w-full';
        }
        if ($val === '100%') {
            return "{$prefix}-full";
        }
        if ($val === 'auto') {
            return "{$prefix}-auto";
        }
        if ($val === 'fit-content') {
            return "{$prefix}-fit";
        }
        if ($val === 'max-content') {
            return "{$prefix}-max";
        }
        if ($val === 'min-content') {
            return "{$prefix}-min";
        }

        if (preg_match('/^([\d.]+)px$/', $val, $m)) {
            $px = (float) $m[1];
            if (floor($px) == $px && isset(self::PX_SCALE[(int) $px])) {
                return "{$prefix}-".self::PX_SCALE[(int) $px];
            }

            return "{$prefix}-[{$val}]";
        }

        if (preg_match('/^([\d.]+)pt$/', $val, $m)) {
            $px = $this->ptToPx((float) $m[1]);
            if (isset(self::PX_SCALE[$px])) {
                return "{$prefix}-".self::PX_SCALE[$px];
            }

            return "{$prefix}-[{$m[1]}pt]";
        }

        return "{$prefix}-[{$val}]";
    }

    private function convertObjectFit(string $val): ?string
    {
        $map = ['cover' => 'object-cover', 'contain' => 'object-contain', 'fill' => 'object-fill', 'none' => 'object-none', 'scale-down' => 'object-scale-down'];

        return $map[$val] ?? null;
    }

    private function innerHtml(\DOMElement $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return trim($html);
    }
}
