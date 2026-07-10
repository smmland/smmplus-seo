<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class SitemapFetcherService
{
    /**
     * Fetches the source sitemap. Transparently follows a <sitemapindex> one level deep
     * so this keeps working if smm.plus later splits its sitemap into an index of files.
     *
     * @return array<int, array{loc:string, lastmod:?string}>
     */
    public function fetchAll(string $sourceUrl): array
    {
        $xml = $this->download($sourceUrl);
        $doc = new SimpleXMLElement($xml);

        if ($doc->getName() === 'sitemapindex') {
            $results = [];
            foreach ($doc->sitemap as $sitemap) {
                $childLoc = (string) $sitemap->loc;
                $results = array_merge($results, $this->fetchAll($childLoc));
            }

            return $results;
        }

        if ($doc->getName() === 'urlset') {
            $results = [];
            foreach ($doc->url as $url) {
                if (! isset($url->loc)) {
                    continue;
                }
                $results[] = [
                    'loc' => (string) $url->loc,
                    'lastmod' => isset($url->lastmod) ? (string) $url->lastmod : null,
                ];
            }

            return $results;
        }

        throw new RuntimeException("Unexpected sitemap root element <{$doc->getName()}> fetched from {$sourceUrl}");
    }

    private function download(string $url): string
    {
        $response = Http::withHeaders([
            'User-Agent' => 'smmplus-seo-sync/1.0 (+https://seo.smm.plus)',
            'Accept' => 'application/xml,text/xml',
        ])->timeout(30)->get($url);

        $response->throw();

        return $response->body();
    }
}
