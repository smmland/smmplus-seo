<?php

namespace App\Services;

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

    // CSS declarations ("property:value") that map to one fixed Tailwind utility class,
    // rather than an arbitrary-value one.
    private const KEYWORD_STYLE_MAP = [
        'font-weight:400' => 'font-normal',
        'font-weight:normal' => 'font-normal',
        'font-weight:700' => 'font-bold',
        'font-weight:bold' => 'font-bold',
        'font-style:normal' => 'not-italic',
        'font-style:italic' => 'italic',
        'text-decoration:none' => 'no-underline',
        'text-decoration:underline' => 'underline',
        'vertical-align:baseline' => 'align-baseline',
        'vertical-align:top' => 'align-top',
        'vertical-align:middle' => 'align-middle',
        'vertical-align:bottom' => 'align-bottom',
        'white-space:pre' => 'whitespace-pre',
        'white-space:pre-wrap' => 'whitespace-pre-wrap',
        'white-space:nowrap' => 'whitespace-nowrap',
        'white-space:normal' => 'whitespace-normal',
        'text-align:center' => 'text-center',
        'text-align:left' => 'text-left',
        'text-align:right' => 'text-right',
        'text-align:justify' => 'text-justify',
        'overflow:hidden' => 'overflow-hidden',
        'overflow-wrap:break-word' => 'break-words',
        'list-style-type:disc' => 'list-disc',
        'list-style-type:none' => 'list-none',
        'background-color:transparent' => 'bg-transparent',
        'background-size:cover' => 'bg-cover',
        'background-position:center' => 'bg-center',
        'border:none' => 'border-none',
        'border-collapse:collapse' => 'border-collapse',
        'table-layout:fixed' => 'table-fixed',
    ];

    // CSS properties that map to a Tailwind utility prefix, with the raw value dropped in as
    // an arbitrary value: e.g. font-size:11pt -> text-[11pt].
    private const PREFIX_STYLE_MAP = [
        'font-size' => 'text',
        'color' => 'text',
        'background-color' => 'bg',
        'background-image' => 'bg',
        'font-family' => 'font',
        'line-height' => 'leading',
        'margin-top' => 'mt',
        'margin-bottom' => 'mb',
        'margin-left' => 'ml',
        'margin-right' => 'mr',
        'margin' => 'm',
        'padding-top' => 'pt',
        'padding-bottom' => 'pb',
        'padding-left' => 'pl',
        'padding-right' => 'pr',
        'padding-inline-start' => 'ps',
        'padding-inline-end' => 'pe',
        'padding' => 'p',
        'width' => 'w',
        'height' => 'h',
        'border-radius' => 'rounded',
    ];

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
        $stylesConverted = $this->convertStyles($container);

        // Belt-and-suspenders: if the body's first block happens to just repeat the title
        // verbatim (a duplicate accidentally left in the source content), drop it - it isn't
        // reachable via the class-scoped lookups above, but source content itself can still
        // contain a stray duplicate.
        $this->stripLeadingDuplicateTitle($container, $articleTitle);

        $this->polishPresentation($xpath, $container);

        $contentHtml = $this->innerHtml($container);

        Storage::disk('public')->put("{$baseDir}/content-{$lang}.html", $contentHtml);

        $previewTitle = e($articleTitle ?? $row->slug);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="{$lang}">
            <head>
            <meta charset="utf-8">
            <title>{$previewTitle} - preview</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="mx-auto max-w-3xl px-6 py-10">
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
     * Presentation touches that go beyond a straight style->class conversion: round the corners
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
            // inline width style already converted to e.g. w-[451pt] shouldn't also get w-full,
            // which would leave two conflicting width classes.
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

    private function convertStyles(\DOMElement $container): int
    {
        $xpath = new \DOMXPath($container->ownerDocument);
        $converted = 0;

        foreach ($xpath->query(".//*[@style]", $container) as $el) {
            $style = $el->getAttribute('style');
            $classes = $this->styleStringToClasses($style);

            if ($classes === '') {
                $el->removeAttribute('style');

                continue;
            }

            $existingClass = trim($el->getAttribute('class'));
            $el->setAttribute('class', trim($existingClass.' '.$classes));
            $el->removeAttribute('style');
            $converted++;
        }

        return $converted;
    }

    private function styleStringToClasses(string $style): string
    {
        // Keyed by property, last declaration wins - matches inline-style cascade semantics.
        // Without this, a source fallback pair like "white-space:pre;white-space:pre-wrap;"
        // would become two Tailwind classes for the same property with no defined winner
        // (Tailwind's generated rule order, not source order, would decide), unlike the
        // original where the later declaration unambiguously wins.
        $declarations = [];

        foreach (explode(';', $style) as $declaration) {
            $declaration = trim($declaration);

            if ($declaration === '') {
                continue;
            }

            [$property, $value] = array_pad(explode(':', $declaration, 2), 2, '');
            $property = strtolower(trim($property));
            $value = trim($value);

            if ($property === '' || $value === '') {
                continue;
            }

            $declarations[$property] = $value;
        }

        $tokens = [];

        foreach ($declarations as $property => $value) {
            $tokens[] = $this->cssDeclarationToUtility($property, $value);
        }

        return implode(' ', array_unique($tokens));
    }

    private function cssDeclarationToUtility(string $property, string $value): string
    {
        $keywordKey = $property.':'.strtolower($value);

        if (isset(self::KEYWORD_STYLE_MAP[$keywordKey])) {
            return self::KEYWORD_STYLE_MAP[$keywordKey];
        }

        if (isset(self::PREFIX_STYLE_MAP[$property])) {
            return self::PREFIX_STYLE_MAP[$property].'-['.$this->escapeArbitraryValue($value).']';
        }

        // No direct utility - Tailwind's arbitrary-property syntax still lets us express any
        // raw CSS declaration as a class, so nothing gets silently dropped.
        return '['.$property.':'.$this->escapeArbitraryValue($value).']';
    }

    private function escapeArbitraryValue(string $value): string
    {
        $value = trim($value, "; \t\n\r\0\x0B");
        $value = preg_replace('/\s+/', ' ', $value);

        return str_replace(' ', '_', $value);
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
