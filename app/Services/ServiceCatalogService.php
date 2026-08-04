<?php

namespace App\Services;

use App\Models\Language;
use App\Models\ServiceTranslation;
use Illuminate\Support\Facades\Http;

class ServiceCatalogService
{
    // Same browser-shaped headers as the blog translation services - without them some requests
    // get a different (often bot-blocked) response than a real visitor would.
    private const FETCH_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // Unlike blog posts (one URL per article), every service lives on this single shared listing
    // page per language - https://{host}/services (default) or https://{host}/{lang}/services.
    // Each service appears twice on the page (a responsive table row and a card), tied together
    // by the same data-filter-table-service-id - only the first occurrence (the table row) is
    // used; the card repeats the identical title/description.
    private const CATEGORY_ROW_CLASS = 'svc-cat-row';

    private const CATEGORY_LABEL_CLASS = 'svc-cat-label';

    private const SERVICE_ROW_CLASS = 'svc-tr';

    private const SERVICE_NAME_CLASS = 'svc-name';

    private const SERVICE_DESC_CLASS = 'svc-desc';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Fetches and parses the services listing page for one language (or the default language,
     * whose URL has no language prefix).
     *
     * @return array{ok: bool, error?: string, categories?: array<string, ?string>, services?: array<int, array{serviceId: string, categoryId: ?string, title: ?string, descriptionHtml: ?string, descriptionText: ?string}>}
     */
    public function fetchAndParse(?string $langCode): array
    {
        $host = parse_url($this->settings->getSourceSitemapUrl(), PHP_URL_HOST);

        if (! $host) {
            return ['ok' => false, 'error' => 'Could not determine the site\'s domain from the configured sitemap URL.'];
        }

        $url = $langCode ? "https://{$host}/{$langCode}/services" : "https://{$host}/services";

        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(20)->get($url);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Fetch error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'error' => 'HTTP '.$response->status().' fetching '.$url];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$response->body());
        libxml_clear_errors();

        $xpath = new \DOMXPath($doc);

        $categories = [];
        foreach ($this->elementsByClass($xpath, self::CATEGORY_ROW_CLASS) as $catNode) {
            $catId = $catNode->getAttribute('data-filter-table-category-id');

            if ($catId === '' || array_key_exists($catId, $categories)) {
                continue;
            }

            $labelNode = $this->firstElementByClass($xpath, self::CATEGORY_LABEL_CLASS, $catNode);
            $categories[$catId] = $labelNode ? $this->cleanText($labelNode->textContent) : null;
        }

        $services = [];
        foreach ($this->elementsByClass($xpath, self::SERVICE_ROW_CLASS) as $rowNode) {
            if (! $rowNode->hasAttribute('data-filter-table-service-id')) {
                continue;
            }

            $serviceId = $rowNode->getAttribute('data-filter-table-service-id');

            // First occurrence wins - the table section (#svcTableWrap) is enumerated before the
            // card section (#svcCardsWrap) in document order, and both describe the same service.
            if ($serviceId === '' || isset($services[$serviceId])) {
                continue;
            }

            $categoryId = $rowNode->getAttribute('data-filter-table-category-id') ?: null;

            $nameNode = $this->firstElementByClass($xpath, self::SERVICE_NAME_CLASS, $rowNode);
            $title = $nameNode ? $this->cleanText($nameNode->textContent) : null;

            $descNode = $this->firstElementByClass($xpath, self::SERVICE_DESC_CLASS, $rowNode);
            $descriptionHtml = $descNode ? trim($this->innerHtml($descNode)) : null;
            $descriptionText = $descNode ? $this->cleanText($descNode->textContent) : null;

            $services[$serviceId] = [
                'serviceId' => $serviceId,
                'categoryId' => $categoryId,
                'title' => $title,
                'descriptionHtml' => $descriptionHtml !== '' ? $descriptionHtml : null,
                'descriptionText' => $descriptionText !== '' ? $descriptionText : null,
            ];
        }

        return ['ok' => true, 'categories' => $categories, 'services' => array_values($services)];
    }

    /**
     * Fetches the default-language services page and upserts one service_translations row per
     * service (lang = default language) - title, category, and description as currently live.
     * When a service's description changes since the last sync, every other language's row for
     * it has its is_translated reset to null, so refreshLanguage() re-checks it instead of
     * trusting a translation made against the old text forever.
     *
     * @return array{ok: bool, error?: string, total?: int, new?: int, changed?: int}
     */
    public function syncDefaultCatalog(): array
    {
        $defaultLang = $this->defaultLang();
        $parsed = $this->fetchAndParse(null);

        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $new = 0;
        $changed = 0;

        foreach ($parsed['services'] as $service) {
            $hash = $service['descriptionText'] !== null ? md5($service['descriptionText']) : null;

            $row = ServiceTranslation::query()->firstOrNew([
                'service_key' => $service['serviceId'],
                'lang' => $defaultLang,
            ]);

            $isNew = ! $row->exists;
            $descriptionChanged = $row->exists && $hash !== null && $row->source_description_hash !== $hash;

            $row->category_id = $service['categoryId'];
            $row->category_title = $parsed['categories'][$service['categoryId']] ?? $row->category_title;
            $row->title = $service['title'];
            $row->description = $service['descriptionHtml'];
            $row->description_text = $service['descriptionText'];
            $row->source_description_hash = $hash;
            $row->checked_at = now();
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            $row->save();

            if ($isNew) {
                $new++;
            } elseif ($descriptionChanged) {
                $changed++;

                ServiceTranslation::query()
                    ->where('service_key', $service['serviceId'])
                    ->where('lang', '!=', $defaultLang)
                    ->update(['is_translated' => null]);
            }
        }

        return ['ok' => true, 'total' => count($parsed['services']), 'new' => $new, 'changed' => $changed];
    }

    /**
     * Fetches one language's services page and, for every service already known from the default
     * catalog (syncDefaultCatalog() must have run at least once first), compares its live
     * description against the default language's to decide whether it's actually translated -
     * exactly the same "some content is genuinely already live in this language, some still just
     * mirrors the default" situation BlogTranslationDetectionService handles per blog URL, just
     * comparing descriptions on one shared page instead of titles across many pages.
     *
     * @return array{ok: bool, error?: string, checked?: int, translated?: int}
     */
    public function refreshLanguage(string $langCode): array
    {
        $defaultLang = $this->defaultLang();

        if ($langCode === $defaultLang) {
            return ['ok' => true, 'checked' => 0, 'translated' => 0];
        }

        $parsed = $this->fetchAndParse($langCode);

        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $defaultRows = ServiceTranslation::query()
            ->where('lang', $defaultLang)
            ->get()
            ->keyBy('service_key');

        $checked = 0;
        $translated = 0;

        foreach ($parsed['services'] as $service) {
            $default = $defaultRows->get($service['serviceId']);

            if (! $default) {
                // Not part of the last known default-language catalog (e.g. syncDefaultCatalog()
                // hasn't run yet, or this language's page has a service the default page didn't) -
                // nothing to compare against, so skip rather than guess.
                continue;
            }

            $isTranslated = $service['descriptionText'] !== null
                && $default->description_text !== null
                && $this->normalize($service['descriptionText']) !== $this->normalize($default->description_text);

            $row = ServiceTranslation::query()->firstOrNew([
                'service_key' => $service['serviceId'],
                'lang' => $langCode,
            ]);

            $row->category_id = $service['categoryId'] ?? $default->category_id;
            $row->category_title = $default->category_title;
            $row->title = $service['title'];
            $row->description = $service['descriptionHtml'];
            $row->description_text = $service['descriptionText'];
            $row->is_translated = $isTranslated;
            $row->checked_at = now();
            $row->check_note = $isTranslated
                ? 'Description differs from the default language - looks translated.'
                : 'Description matches the default language - not translated yet.';
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            $row->save();

            $checked++;

            if ($isTranslated) {
                $translated++;
            }
        }

        return ['ok' => true, 'checked' => $checked, 'translated' => $translated];
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    /**
     * @return list<\DOMElement>
     */
    private function elementsByClass(\DOMXPath $xpath, string $class, ?\DOMElement $context = null): array
    {
        $query = ($context ? '.' : '')."//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]";
        $nodes = $context ? $xpath->query($query, $context) : $xpath->query($query);

        return $nodes ? iterator_to_array($nodes) : [];
    }

    private function firstElementByClass(\DOMXPath $xpath, string $class, \DOMElement $context): ?\DOMElement
    {
        $nodes = $this->elementsByClass($xpath, $class, $context);

        return $nodes[0] ?? null;
    }

    private function innerHtml(\DOMElement $node): string
    {
        $html = '';

        foreach ($node->childNodes as $child) {
            $html .= $node->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    // For values actually stored/displayed (titles, category names, description text) - keeps
    // case, just collapses whitespace and drops any stray tags.
    private function cleanText(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', strip_tags($text)));
    }

    // For comparisons only (is-this-translated? / did-the-source-change?) - case-insensitive on
    // top of cleanText(), so a purely capitalization difference is never mistaken for a real
    // translation or a real content change.
    private function normalize(string $text): string
    {
        return mb_strtolower($this->cleanText($text));
    }
}
