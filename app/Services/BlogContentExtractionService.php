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

    // Only the article body - the title is captured separately (it needs its own translation
    // handling, not lumped in with the body content) and so is everything outside it.
    private const CONTENT_CLASS = 'post-body';

    private const TITLE_CLASS = 'article-title';

    // (attribute, value) pairs identifying each <meta> tag worth translating for SEO. Excludes
    // meta that isn't actual translatable text - og:url/og:image/twitter:image/twitter:card,
    // canonical, hreflang - those are structural/technical, not content.
    private const META_FIELDS = [
        'metaDescription' => ['name', 'description'],
        'metaKeywords' => ['name', 'keywords'],
        'ogTitle' => ['property', 'og:title'],
        'ogDescription' => ['property', 'og:description'],
        'twitterTitle' => ['name', 'twitter:title'],
        'twitterDescription' => ['name', 'twitter:description'],
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
     * Fetches the live English page and pulls out, as three separate pieces:
     *  - the article body only (".post-body") - images downloaded and inline-styles converted
     *    to Tailwind classes;
     *  - the on-page article title (".article-title") - kept apart from the body, since it's
     *    translated/tracked as its own field, not body content;
     *  - the SEO-relevant <meta> text (title tag, description, keywords, OG/Twitter title+description)
     *    - the fields Google actually shows or reads per language, not structural tags like
     *      canonical/hreflang/og:image.
     *
     * @return array{ok: bool, error?: string, imagesDownloaded?: int, imagesInlined?: int, stylesConverted?: int, contentPath?: string, metaPath?: string, previewUrl?: string, contentUrl?: string, articleTitle?: ?string}
     */
    public function extract(Url $englishRow): array
    {
        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(20)->get($englishRow->source_url);
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
        $container = $this->firstElementByClass($xpath, self::CONTENT_CLASS);

        if (! $container) {
            return ['ok' => false, 'error' => 'Could not find the article content container on the page (expected a "'.self::CONTENT_CLASS.'" element).'];
        }

        $titleNode = $this->firstElementByClass($xpath, self::TITLE_CLASS);
        $articleTitle = $titleNode ? $this->normalizeWhitespace($titleNode->textContent) : null;

        $seo = $this->extractSeoMeta($xpath);
        $seo['articleTitle'] = $articleTitle;

        $slug = $englishRow->slug ?: 'untitled';
        $baseDir = "blog/{$slug}";

        $imageStats = $this->processImages($container, $doc, $baseDir);
        $stylesConverted = $this->convertStyles($container);

        $contentHtml = $this->innerHtml($container);

        Storage::disk('public')->put("{$baseDir}/content-en.html", $contentHtml);

        $metaJson = json_encode($seo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        Storage::disk('public')->put("{$baseDir}/meta-en.json", $metaJson);

        $previewTitle = e($articleTitle ?? $englishRow->slug);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="en">
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

        Storage::disk('public')->put("{$baseDir}/preview.html", $previewHtml);

        $contentPath = "{$baseDir}/content-en.html";
        $metaPath = "{$baseDir}/meta-en.json";

        $englishRow->content_extracted_at = now();
        $englishRow->content_extraction_path = $contentPath;
        $englishRow->seo_extraction_path = $metaPath;
        $englishRow->save();

        return [
            'ok' => true,
            'imagesDownloaded' => $imageStats['downloaded'],
            'imagesInlined' => $imageStats['inlined'],
            'stylesConverted' => $stylesConverted,
            'contentPath' => $contentPath,
            'metaPath' => $metaPath,
            'contentUrl' => $this->assetUrl($contentPath),
            'metaUrl' => $this->assetUrl($metaPath),
            'previewUrl' => $this->assetUrl("{$baseDir}/preview.html"),
            'articleTitle' => $articleTitle,
        ];
    }

    private function firstElementByClass(\DOMXPath $xpath, string $class): ?\DOMElement
    {
        $nodes = $xpath->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]");

        return ($nodes && $nodes->length > 0) ? $nodes->item(0) : null;
    }

    /**
     * @return array<string, ?string>
     */
    private function extractSeoMeta(\DOMXPath $xpath): array
    {
        $titleNodes = $xpath->query('//title');
        $meta = [
            'pageTitle' => $titleNodes->length > 0 ? $this->normalizeWhitespace($titleNodes->item(0)->textContent) : null,
        ];

        foreach (self::META_FIELDS as $key => [$attr, $value]) {
            $nodes = $xpath->query("//meta[@{$attr}='{$value}']/@content");
            $meta[$key] = $nodes->length > 0 ? $this->normalizeWhitespace($nodes->item(0)->nodeValue) : null;
        }

        return $meta;
    }

    private function normalizeWhitespace(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', $text));
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
