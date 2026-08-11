<?php

namespace App\Services;

use App\Models\CategoryTranslation;
use App\Models\Language;
use App\Models\ServiceTranslation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
     * Also collects addedServices/changedServices/removedServices - the same per-row detection
     * this method already does for $new/$changed, just returned as detail instead of only a
     * count, for TelegramPostGeneratorService::draftServiceChanges() to turn into channel
     * announcements. Removal is detected by elimination: any default-language row not marked
     * removed_at yet that isn't touched by this sync (not present in the freshly parsed page) has
     * removed_at set once here - a service that later reappears is silently un-flagged (see the
     * loop below) rather than triggering a second announcement.
     *
     * @return array{ok: bool, error?: string, total?: int, new?: int, changed?: int, addedServices?: list<array{service_key: string, title: ?string, category_title: ?string}>, changedServices?: list<array{service_key: string, title: ?string, category_title: ?string}>, removedServices?: list<array{service_key: string, title: ?string, category_title: ?string}>}
     */
    public function syncDefaultCatalog(): array
    {
        $defaultLang = $this->defaultLang();
        $parsed = $this->fetchAndParse(null);

        if (! $parsed['ok']) {
            return ['ok' => false, 'error' => $parsed['error']];
        }

        $previouslyKnownKeys = ServiceTranslation::query()
            ->where('lang', $defaultLang)
            ->whereNull('removed_at')
            ->pluck('service_key')
            ->all();

        $new = 0;
        $changed = 0;
        $addedServices = [];
        $changedServices = [];
        $touchedKeys = [];

        foreach ($parsed['services'] as $service) {
            $touchedKeys[] = $service['serviceId'];

            $hash = $service['descriptionText'] !== null ? md5($service['descriptionText']) : null;
            $titleHash = $service['title'] !== null ? md5($service['title']) : null;

            $row = ServiceTranslation::query()->firstOrNew([
                'service_key' => $service['serviceId'],
                'lang' => $defaultLang,
            ]);

            $isNew = ! $row->exists;
            $descriptionChanged = $row->exists && $hash !== null && $row->source_description_hash !== $hash;
            $titleChanged = $row->exists && $titleHash !== null && $row->source_title_hash !== $titleHash;

            $row->category_id = $service['categoryId'];
            $row->category_title = $parsed['categories'][$service['categoryId']] ?? $row->category_title;
            $row->title = $service['title'];
            $row->description = $service['descriptionHtml'];
            $row->description_text = $service['descriptionText'];
            $row->source_description_hash = $hash;
            $row->source_title_hash = $titleHash;
            $row->checked_at = now();
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            // Present in this sync, so it's not gone (whether or not it was previously flagged
            // removed) - a service that disappears and later comes back is resumed silently.
            $row->removed_at = null;
            $row->save();

            if ($isNew) {
                $new++;
                $addedServices[] = ['service_key' => $row->service_key, 'title' => $row->title, 'category_title' => $row->category_title];
            } else {
                if ($descriptionChanged) {
                    $changed++;

                    ServiceTranslation::query()
                        ->where('service_key', $service['serviceId'])
                        ->where('lang', '!=', $defaultLang)
                        ->update(['is_translated' => null]);
                }

                // Independent of the description reset above - a title-only edit on the source
                // site shouldn't force every language's description to be re-checked, and vice
                // versa.
                if ($titleChanged) {
                    ServiceTranslation::query()
                        ->where('service_key', $service['serviceId'])
                        ->where('lang', '!=', $defaultLang)
                        ->update(['is_title_translated' => null]);
                }

                // Broader than the legacy $changed counter above (description-only, kept as-is
                // for the existing sync-result UI text) - a title-only change is still worth its
                // own Telegram announcement.
                if ($descriptionChanged || $titleChanged) {
                    $changedServices[] = ['service_key' => $row->service_key, 'title' => $row->title, 'category_title' => $row->category_title];
                }
            }
        }

        $removedKeys = array_diff($previouslyKnownKeys, $touchedKeys);
        $removedServices = [];

        if (! empty($removedKeys)) {
            $removedRows = ServiceTranslation::query()
                ->where('lang', $defaultLang)
                ->whereIn('service_key', $removedKeys)
                ->get();

            foreach ($removedRows as $row) {
                $row->removed_at = now();
                $row->save();

                $removedServices[] = ['service_key' => $row->service_key, 'title' => $row->title, 'category_title' => $row->category_title];
            }
        }

        $this->syncDefaultCategories($parsed['categories'], $defaultLang);

        return [
            'ok' => true,
            'total' => count($parsed['services']),
            'new' => $new,
            'changed' => $changed,
            'addedServices' => $addedServices,
            'changedServices' => $changedServices,
            'removedServices' => $removedServices,
        ];
    }

    /**
     * The category counterpart to the per-service loop above - upserts one category_translations
     * row (default language) per category found on this sync, hashing the label the same way
     * source_description_hash/source_title_hash already do, and resetting every other language's
     * is_translated to null when it changed so refreshLanguage() re-checks it instead of trusting
     * a translation made against the old name forever. Schema-guarded since category_translations
     * is a newer table than service_translations - an admin who hasn't clicked "Update database"
     * yet after this feature shipped would otherwise crash the whole sync over a table this
     * specific loop doesn't strictly need to touch.
     *
     * @param  array<string, ?string>  $categories
     */
    private function syncDefaultCategories(array $categories, string $defaultLang): void
    {
        if (! Schema::hasTable('category_translations')) {
            return;
        }

        foreach ($categories as $categoryId => $label) {
            if ($categoryId === '') {
                continue;
            }

            // PHP silently casts a purely-numeric string array key (e.g. "7") to an int, so a
            // digit-only category id would otherwise be compared/bound against the
            // string-typed category_id column as an int from here on. Cast back explicitly
            // rather than relying on whichever DB driver happens to coerce it correctly.
            $categoryId = (string) $categoryId;

            $hash = $label !== null ? md5($label) : null;

            $row = CategoryTranslation::query()->firstOrNew([
                'category_id' => $categoryId,
                'lang' => $defaultLang,
            ]);

            $changed = $row->exists && $hash !== null && $row->source_title_hash !== $hash;
            $previousTitle = $row->title;

            $row->title = $label;
            $row->source_title_hash = $hash;
            $row->checked_at = now();
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            $row->save();

            if ($changed) {
                // Temporary diagnostic: an admin reported categories getting auto-re-translated
                // over and over even though nothing looked different on the site. If the label
                // text genuinely isn't changing, something invisible in it is (whitespace variant,
                // stray markup caught by textContent, a value that's technically live but not a
                // real rename) - logging both raw strings here, byte for byte, is the fastest way
                // to actually see what differs instead of guessing at it blind.
                Log::info('Category translation: source name hash changed, resetting other-language translations', [
                    'category_id' => $categoryId,
                    'previous_title' => $previousTitle,
                    'previous_title_bytes' => $previousTitle !== null ? bin2hex($previousTitle) : null,
                    'new_title' => $label,
                    'new_title_bytes' => $label !== null ? bin2hex($label) : null,
                ]);

                CategoryTranslation::query()
                    ->where('category_id', $categoryId)
                    ->where('lang', '!=', $defaultLang)
                    ->update(['is_translated' => null]);
            }
        }
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

            $liveDiffersFromDefault = $service['descriptionText'] !== null
                && $default->description_text !== null
                && $this->normalize($service['descriptionText']) !== $this->normalize($default->description_text);

            $titleLiveDiffersFromDefault = $service['title'] !== null
                && $default->title !== null
                && $this->normalize($service['title']) !== $this->normalize($default->title);

            $row = ServiceTranslation::query()->firstOrNew([
                'service_key' => $service['serviceId'],
                'lang' => $langCode,
            ]);

            // Whether we already have real translated content stashed on this row, worth
            // protecting from being wiped out just because the live site hasn't picked it up
            // yet. Keyed off translated_at (only ever set by ServiceAiTranslationService when it
            // actually saves a translation) rather than "does the stored text differ from the
            // default" - some words genuinely translate to themselves (brand names, etc.), and a
            // text-difference check would treat those as "not translated" forever, which is what
            // caused an actual infinite retranslation loop: queueMissing() sees !looksTranslated()
            // -> requeues -> AI translates it (correctly, identically to the default) -> next
            // sync's text comparison still says "no difference" -> is_translated reset back to
            // false -> requeued again, forever.
            //
            // The trailing hash check is what actually lets a *stale* translation (default
            // description changed since this row was translated) fall through to the "else"
            // branch below instead of being protected forever: description_translated_from_hash
            // records what the default's hash was at translation time (ServiceAiTranslationService) -
            // null for rows translated before that column existed, which keeps their existing
            // (already-verified) protection rather than reinterpreting them as stale.
            $hasOwnTranslation = $row->exists
                && filled($row->description_text)
                && $row->translated_at !== null
                && ($row->description_translated_from_hash === null || $row->description_translated_from_hash === $default->source_description_hash);

            // A translation that comes back identical to the default text isn't a bug (brand
            // names, numbers, etc. often don't change between languages) - and there's no way to
            // tell that apart from "not translated yet" by scraping the live site, since both
            // cases show the exact same text there. Once we have a real, fresh translation
            // attempt on record, treat an identical match as confirmed immediately rather than
            // waiting forever for a live-site difference that, by definition, will never appear.
            $translationMatchesDefault = $hasOwnTranslation
                && $default->description_text !== null
                && $this->normalize($row->description_text) === $this->normalize($default->description_text);

            // Same idea, independent of the description one above - the title and description
            // of a row can each be in a different state (title uploaded already, description
            // still waiting, or vice versa).
            $hasOwnTitleTranslation = $row->exists
                && filled($row->title)
                && $row->title_translated_at !== null
                && ($row->title_translated_from_hash === null || $row->title_translated_from_hash === $default->source_title_hash);

            $titleTranslationMatchesDefault = $hasOwnTitleTranslation
                && $default->title !== null
                && $this->normalize($row->title) === $this->normalize($default->title);

            $category_id = $service['categoryId'] ?? $default->category_id;
            $category_title = $default->category_title;

            if ($liveDiffersFromDefault || $translationMatchesDefault) {
                $row->category_id = $category_id;
                $row->category_title = $category_title;
                if ($liveDiffersFromDefault) {
                    $row->description = $service['descriptionHtml'];
                    $row->description_text = $service['descriptionText'];
                }
                $row->is_translated = true;
                $row->live_confirmed_at = now();
                $row->check_note = $liveDiffersFromDefault
                    ? 'Confirmed live - description differs from the default language.'
                    : 'Confirmed - the translation genuinely matches the default language description (e.g. a brand/product name).';
            } elseif ($hasOwnTranslation) {
                // We have a translation saved, but the live site still shows the default text -
                // keep it intact rather than overwriting it with what's still just the default
                // content, and leave live_confirmed_at alone so needsSiteUpdate() keeps flagging
                // it as not uploaded yet. is_translated is set explicitly (not just left as
                // whatever it already was) since syncDefaultCatalog() may have reset it to null
                // to force this re-check in the first place.
                $row->is_translated = true;
                $row->check_note = 'Translated here, but the live site still shows the default-language description.';
            } else {
                $row->category_id = $category_id;
                $row->category_title = $category_title;
                $row->description = $service['descriptionHtml'];
                $row->description_text = $service['descriptionText'];
                $row->is_translated = false;
                $row->check_note = 'Description matches the default language - not translated yet.';
            }

            // Same three-way branch as the description one above, applied to the title
            // independently - see AiSettingsService::SERVICE_TITLE_TRANSLATION_PLACEHOLDERS for
            // the AI side of this.
            if ($titleLiveDiffersFromDefault || $titleTranslationMatchesDefault) {
                if ($titleLiveDiffersFromDefault) {
                    $row->title = $service['title'];
                }
                $row->is_title_translated = true;
                $row->title_live_confirmed_at = now();
                $row->title_check_note = $titleLiveDiffersFromDefault
                    ? 'Confirmed live - title differs from the default language.'
                    : 'Confirmed - the translation genuinely matches the default language title (e.g. a brand/product name).';
            } elseif ($hasOwnTitleTranslation) {
                $row->is_title_translated = true;
                $row->title_check_note = 'Translated here, but the live site still shows the default-language title.';
            } else {
                $row->title = $service['title'];
                $row->is_title_translated = false;
                $row->title_check_note = 'Title matches the default language - not translated yet.';
            }

            $row->checked_at = now();
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            $row->save();

            $checked++;

            if ($row->is_translated) {
                $translated++;
            }
        }

        $this->refreshLanguageCategories($parsed['categories'], $langCode, $defaultLang);

        return ['ok' => true, 'checked' => $checked, 'translated' => $translated];
    }

    /**
     * The category counterpart to the per-service loop above, reusing $parsed['categories'] from
     * the exact same page fetch refreshLanguage() already made - no extra HTTP call. Same
     * three-way branch as the per-service title check just above: live differs from default ->
     * confirmed live; a translation is already stashed here but the live site still shows the
     * default -> keep it, still flagged as not-yet-uploaded; neither -> not translated.
     *
     * @param  array<string, ?string>  $categories
     */
    private function refreshLanguageCategories(array $categories, string $langCode, string $defaultLang): void
    {
        if (! Schema::hasTable('category_translations')) {
            return;
        }

        $defaultRows = CategoryTranslation::query()
            ->where('lang', $defaultLang)
            ->get()
            ->keyBy('category_id');

        foreach ($categories as $categoryId => $label) {
            if ($categoryId === '') {
                continue;
            }

            // Same PHP numeric-string-array-key gotcha as syncDefaultCategories() above.
            $categoryId = (string) $categoryId;

            $default = $defaultRows->get($categoryId);

            if (! $default) {
                continue;
            }

            $liveDiffersFromDefault = $label !== null
                && $default->title !== null
                && $this->normalize($label) !== $this->normalize($default->title);

            $row = CategoryTranslation::query()->firstOrNew([
                'category_id' => $categoryId,
                'lang' => $langCode,
            ]);

            // Keyed off translated_at (only ever set by CategoryAiTranslationService when it
            // actually saves a translation), not "does the stored text differ from the default" -
            // some category names genuinely translate to themselves (brand names, etc.), and a
            // text-difference check would treat those as "not translated" forever. See the
            // matching comment on the per-service description check in refreshLanguage() above
            // for the exact infinite-retranslation-loop this caused.
            $hasOwnTranslation = $row->exists
                && filled($row->title)
                && $row->translated_at !== null
                && ($row->title_translated_from_hash === null || $row->title_translated_from_hash === $default->source_title_hash);

            // A translation identical to the default is still a real translation (e.g. a brand
            // name that correctly stays the same across languages) - there's no way to tell that
            // apart from "not translated yet" by scraping the live site, since both show the same
            // text there. Treat a fresh, real translation that matches as confirmed immediately
            // rather than waiting forever for a live-site difference that will never appear.
            $translationMatchesDefault = $hasOwnTranslation
                && $default->title !== null
                && $this->normalize($row->title) === $this->normalize($default->title);

            if ($liveDiffersFromDefault || $translationMatchesDefault) {
                if ($liveDiffersFromDefault) {
                    $row->title = $label;
                }
                $row->is_translated = true;
                $row->live_confirmed_at = now();
                $row->check_note = $liveDiffersFromDefault
                    ? 'Confirmed live - name differs from the default language.'
                    : 'Confirmed - the translation genuinely matches the default language name (e.g. a brand name).';
            } elseif ($hasOwnTranslation) {
                $row->is_translated = true;
                $row->check_note = 'Translated here, but the live site still shows the default-language name.';
            } else {
                $row->title = $label;
                $row->is_translated = false;
                $row->check_note = 'Name matches the default language - not translated yet.';
            }

            $row->checked_at = now();
            $row->first_seen_at = $row->first_seen_at ?? now();
            $row->last_seen_at = now();
            $row->save();
        }
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
        $text = strip_tags($text);

        // A non-breaking space (U+00A0, e.g. from a rendered &nbsp;) collapses to a regular space
        // like any other whitespace - PCRE's \s without the /u modifier only matches single-byte
        // ASCII whitespace, so a UTF-8 NBSP would otherwise survive untouched and make two
        // visually-identical strings (e.g. a category name with a regular space one sync, an NBSP
        // the next) hash differently, which reads as "the source changed" and wipes every other
        // language's translation for nothing.
        $text = str_replace("\xC2\xA0", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    // For comparisons only (is-this-translated? / did-the-source-change?) - case-insensitive on
    // top of cleanText(), so a purely capitalization difference is never mistaken for a real
    // translation or a real content change.
    private function normalize(string $text): string
    {
        return mb_strtolower($this->cleanText($text));
    }
}
