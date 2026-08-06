<?php

namespace App\Services;

use App\Models\Url;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class SitemapGeneratorService
{
    public const CATEGORIES = ['pages', 'blog', 'landing', 'other'];

    private const CATEGORY_BY_PATTERN = [
        'HOME' => 'pages',
        'STATIC' => 'pages',
        'BLOG' => 'blog',
        'LANDING' => 'landing',
        'UTILITY' => 'other',
        'OTHER' => 'other',
    ];

    private const SITEMAP_XMLNS = 'http://www.sitemaps.org/schemas/sitemap/0.9';
    private const XHTML_XMLNS = 'http://www.w3.org/1999/xhtml';
    private const CACHE_KEY = 'sitemap-generated-set';
    private const CACHE_TTL_SECONDS = 86400;

    /**
     * @return array{index:string, categories:array<string,?string>, generated_at:string}
     */
    public function generate(): array
    {
        // Ordered by group_key then lang so every translation of the same page ends up
        // adjacent in the output, instead of in whatever order the table scan happens to
        // return rows in (effectively insertion order, unrelated to the page each row belongs
        // to) - the previously unordered output is what made the generated sitemap look
        // shuffled/unsorted despite every individual entry being correct.
        $urls = Url::query()->where('is_active', true)->where('is_hidden', false)
            ->orderBy('group_key')
            ->orderBy('lang')
            ->get();

        $byGroupKey = [];
        foreach ($urls as $url) {
            $byGroupKey[$url->group_key][] = $url;
        }

        // array_fill_keys(..., collect()) would share one Collection instance across every
        // key (arrays copy object handles, not values), so build each bucket separately.
        $byCategory = [];
        foreach (self::CATEGORIES as $category) {
            $byCategory[$category] = [];
        }
        foreach ($urls as $url) {
            $category = self::CATEGORY_BY_PATTERN[$url->pattern_type];
            $byCategory[$category][] = $url;
        }
        foreach (self::CATEGORIES as $category) {
            $byCategory[$category] = collect($byCategory[$category]);
        }

        $categories = [];
        $categoryLastmod = [];

        foreach (self::CATEGORIES as $category) {
            $list = $byCategory[$category];
            // An empty <urlset> is technically well-formed but Google Search Console flags it
            // as an error ("couldn't fetch" / no valid URLs), so skip publishing it entirely.
            $categories[$category] = $list->isNotEmpty() ? $this->buildUrlsetXml($list, $byGroupKey) : null;
            $categoryLastmod[$category] = $list
                ->pluck('source_lastmod')
                ->filter()
                ->max();
        }

        $result = [
            'index' => $this->buildIndexXml($categoryLastmod, $categories),
            'categories' => $categories,
            'generated_at' => now()->toIso8601String(),
        ];

        Cache::put(self::CACHE_KEY, $result, self::CACHE_TTL_SECONDS);

        return $result;
    }

    public function getIndexXml(): string
    {
        return $this->cached()['index'];
    }

    public function getCategoryXml(string $category): ?string
    {
        return $this->cached()['categories'][$category] ?? null;
    }

    public function getLastGeneratedAt(): ?string
    {
        return Cache::get(self::CACHE_KEY)['generated_at'] ?? null;
    }

    private function cached(): array
    {
        return Cache::get(self::CACHE_KEY) ?? $this->generate();
    }

    private function buildUrlsetXml($list, array $byGroupKey): string
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $urlset = $doc->createElementNS(self::SITEMAP_XMLNS, 'urlset');
        $urlset->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xhtml', self::XHTML_XMLNS);
        $doc->appendChild($urlset);

        foreach ($list as $u) {
            $urlNode = $doc->createElement('url');
            $urlset->appendChild($urlNode);

            // DOMDocument::createElement() escapes the text content itself; don't double-escape here.
            $urlNode->appendChild($doc->createElement('loc', $u->source_url));

            if ($u->source_lastmod) {
                $urlNode->appendChild($doc->createElement('lastmod', $u->source_lastmod->toIso8601String()));
            }
            if ($u->changefreq) {
                $urlNode->appendChild($doc->createElement('changefreq', $u->changefreq));
            }
            if ($u->priority !== null) {
                $urlNode->appendChild($doc->createElement('priority', (string) $u->priority));
            }

            $alternates = collect($byGroupKey[$u->group_key] ?? [])
                ->filter(fn ($alt) => $alt->source_url !== $u->source_url || $alt->lang !== $u->lang);

            $links = collect([['lang' => $u->lang, 'href' => $u->source_url]])
                ->concat($alternates->map(fn ($alt) => ['lang' => $alt->lang, 'href' => $alt->source_url]));

            if ($links->count() > 1) {
                foreach ($links as $link) {
                    $linkNode = $doc->createElementNS(self::XHTML_XMLNS, 'xhtml:link');
                    $linkNode->setAttribute('rel', 'alternate');
                    $linkNode->setAttribute('hreflang', $link['lang']);
                    $linkNode->setAttribute('href', $link['href']);
                    $urlNode->appendChild($linkNode);
                }
            }
        }

        return $doc->saveXML();
    }

    /**
     * @param  array<string,?string>  $categories
     */
    private function buildIndexXml(array $categoryLastmod, array $categories): string
    {
        $base = rtrim(config('app.url'), '/');

        $doc = new \DOMDocument('1.0', 'UTF-8');
        $doc->formatOutput = true;

        $index = $doc->createElementNS(self::SITEMAP_XMLNS, 'sitemapindex');
        $doc->appendChild($index);

        foreach (self::CATEGORIES as $category) {
            if ($categories[$category] === null) {
                continue;
            }

            $sitemapNode = $doc->createElement('sitemap');
            $index->appendChild($sitemapNode);
            $sitemapNode->appendChild($doc->createElement('loc', "{$base}/sitemap-{$category}.xml"));

            $lastmod = $categoryLastmod[$category] ?? null;
            if ($lastmod) {
                $sitemapNode->appendChild($doc->createElement('lastmod', $lastmod->toIso8601String()));
            }
        }

        return $doc->saveXML();
    }
}
