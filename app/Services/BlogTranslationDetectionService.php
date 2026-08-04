<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

class BlogTranslationDetectionService
{
    // A browser-shaped User-Agent + Accept headers. Without these, Guzzle's default
    // "GuzzleHttp/7" UA gets a different response from some hosts' bot/WAF protection than a
    // real visitor would - which can make two genuinely different pages come back as the same
    // challenge/interstitial page, and therefore look like an untranslated match.
    private const FETCH_HEADERS = [
        'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        'Accept-Language' => 'en-US,en;q=0.9',
    ];

    // Titles that indicate we didn't actually get real page content (a bot-check/interstitial),
    // not that the page is genuinely untranslated. If we compared these we could match two
    // pages that are both blocked rather than both English - a false "untranslated" positive.
    private const CHALLENGE_TITLE_MARKERS = [
        'just a moment', 'attention required', 'access denied', 'are you human',
        '403 forbidden', 'cloudflare', 'checking your browser', 'security check',
    ];

    // Same class BlogContentExtractionService reads the visible article heading from. Some pages
    // on this site keep the <title> tag (and og:title/twitter:title) in the default language even
    // once the real, visible heading and body have genuinely been translated - an SEO-title field
    // that was apparently never filled in for that article, not a real translation gap. Comparing
    // this heading too, alongside the <title>, catches those pages that the title comparison alone
    // would wrongly call "not translated".
    private const ARTICLE_HEADING_CLASS = 'article-title';

    public function __construct(
        private readonly TranslationSettingsService $settings,
        private readonly UrlClassifierService $classifier,
    ) {}

    // Memoizes soft404Title() per host+lang for the lifetime of one request - a batch check
    // touching many rows of the same language on the same site would otherwise probe once per
    // row instead of once total.
    private array $soft404TitleCache = [];

    /**
     * Re-fetches the live <title> for every non-default-language blog URL that's due for a
     * check, compares it against its topic's default-language (English) title, and - only for
     * rows we're already in control of - hides/unhides based on the result.
     *
     * $limit bounds how many URLs get fetched in one call, most-overdue first - this runs on a
     * host with no queue workers and a real PHP execution time limit, so an unbounded fetch loop
     * (one HTTP request per URL) is a genuine timeout risk once there are more than a couple
     * dozen blog URLs. A large backlog just gets worked off across several runs instead - either
     * by the hourly schedule, or by clicking "Run now" again (or use pendingCount() below /
     * refreshOne() for a single URL, to check without waiting on the batch).
     *
     * @return array{checked:int, hidden:int, unhidden:int, errors:int}
     */
    public function refresh(bool $force = false, int $limit = 200): array
    {
        $defaultLang = $this->defaultLang();
        $autoHideEnabled = $this->settings->isAutoHideEnabled();
        $cutoff = now()->subHours($this->settings->getRecheckIntervalHours());

        $candidates = $this->dueQuery($defaultLang, $force ? null : $cutoff)
            ->orderByRaw('translation_checked_at is not null')
            ->orderBy('translation_checked_at')
            ->limit($limit)
            ->get()
            ->groupBy('group_key');

        $totals = ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 0];

        foreach ($candidates as $groupKey => $rows) {
            $defaultRow = Url::query()
                ->where('group_key', $groupKey)
                ->where('lang', $defaultLang)
                ->first();

            if (! $defaultRow) {
                continue;
            }

            [$defaultTitle, $defaultHeading] = $this->fetchTitle($defaultRow->source_url);

            if ($defaultTitle === null) {
                $totals['errors'] += $rows->count();

                continue;
            }

            foreach ($rows as $row) {
                $this->mergeTotals($totals, $this->checkRow($row, $defaultTitle, $defaultHeading, $autoHideEnabled));
            }
        }

        return $totals;
    }

    /**
     * Same check as refresh(), but for exactly one URL - fetched immediately, ignoring the
     * recheck cycle and the batch limit. For manually re-verifying a single link from the admin
     * panel instead of waiting for its turn in the batch.
     *
     * @return array{checked:int, hidden:int, unhidden:int, errors:int}
     */
    public function refreshOne(Url $row): array
    {
        $defaultLang = $this->defaultLang();

        if ($row->lang === $defaultLang) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 0];
        }

        $defaultRow = Url::query()
            ->where('group_key', $row->group_key)
            ->where('lang', $defaultLang)
            ->first();

        if (! $defaultRow) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 1];
        }

        [$defaultTitle, $defaultHeading] = $this->fetchTitle($defaultRow->source_url);

        if ($defaultTitle === null) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 1];
        }

        return $this->checkRow($row, $defaultTitle, $defaultHeading, $this->settings->isAutoHideEnabled());
    }

    /**
     * For a language with no Url row at all yet - refreshOne()/refreshTopic() both need an
     * existing row to update, but a language that's never been translated *in this tool* has
     * none. Builds the same guessed URL pattern BlogAiTranslationService uses
     * (https://host/{lang}/blog/{slug}), fetches it, and - only if its title looks genuinely
     * different from the default language's - creates the row now, as if it had just been
     * translated. This is for content translated entirely outside this tool (e.g. edited
     * directly in the site's own CMS) that the admin knows is already live and just wants this
     * panel to notice, without going through "Translate with AI" at all.
     *
     * @return array{ok: bool, message: string}
     */
    public function checkMissingLanguage(string $groupKey, string $targetLangCode): array
    {
        $defaultLang = $this->defaultLang();

        $defaultRow = Url::query()->where('group_key', $groupKey)->where('lang', $defaultLang)->first();

        if (! $defaultRow) {
            return ['ok' => false, 'message' => 'Could not find the default-language content for this topic.'];
        }

        [$defaultTitle, $defaultHeading] = $this->fetchTitle($defaultRow->source_url);

        if ($defaultTitle === null) {
            return ['ok' => false, 'message' => 'Could not fetch the default-language page to compare against right now - try again shortly.'];
        }

        $scheme = parse_url($defaultRow->source_url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($defaultRow->source_url, PHP_URL_HOST);
        $candidateUrl = "{$scheme}://{$host}/{$targetLangCode}/blog/{$defaultRow->slug}";

        [$title, $heading, $note] = $this->fetchTitle($candidateUrl);

        if ($title === null) {
            return ['ok' => false, 'message' => "Still not live at the expected address ({$note})."];
        }

        $titleMatchesDefault = $this->normalize($title) === $this->normalize($defaultTitle);
        $headingMatchesDefault = ($heading !== null && $defaultHeading !== null)
            ? $this->normalize($heading) === $this->normalize($defaultHeading)
            : true;

        // Some pages on this site keep the <title> tag in the default language even once the
        // real heading and body are genuinely translated (see ARTICLE_HEADING_CLASS above) - only
        // call it "not translated yet" when neither signal shows a difference.
        if ($titleMatchesDefault && $headingMatchesDefault) {
            return ['ok' => false, 'message' => "Found a page there, but its title matches the default language - doesn't look translated yet (title: \"{$title}\")."];
        }

        // Same soft-404 guard as checkRow() below - a title differing from the default language
        // isn't proof of a real translation if the site returns this same page for any
        // nonexistent URL under this language prefix.
        $soft404Title = $this->soft404Title($scheme, $host, $targetLangCode);

        if ($soft404Title !== null && $this->normalize($title) === $this->normalize($soft404Title)) {
            return ['ok' => false, 'message' => "Found a page there, but it looks like this site's catch-all/not-found page for missing {$targetLangCode} pages, not a real translation (title: \"{$title}\")."];
        }

        $classified = $this->classifier->classify($candidateUrl);

        $row = Url::query()->firstOrNew(['group_key' => $groupKey, 'lang' => $targetLangCode]);
        $row->source_url = $candidateUrl;
        $row->path = $classified['path'];
        $row->pattern_type = $defaultRow->pattern_type;
        $row->slug = $defaultRow->slug;
        $row->is_active = true;
        // Exempts this row from SyncService's "not in the sitemap -> deactivate" pruning
        // permanently - its source_url is a guessed pattern, not something ever pulled from a
        // real sitemap, so it's expected to keep being absent from future sitemap fetches too.
        if (Schema::hasColumn('urls', 'is_ai_guessed')) {
            $row->is_ai_guessed = true;
        }
        $row->is_translated = true;
        $row->translation_title = $title;
        $row->translation_checked_at = now();
        $row->translation_check_note = $note;
        $row->first_seen_at = $row->first_seen_at ?? now();
        $row->last_seen_at = now();
        $row->save();

        return ['ok' => true, 'message' => "Found it live - title: \"{$title}\"."];
    }

    /**
     * Same check as refresh(), but for every non-default-language URL belonging to one topic
     * (group_key) - fetched immediately, ignoring the recheck cycle and the batch limit. For
     * manually re-verifying a whole topic's row on the Blog Translation queue page.
     *
     * @return array{checked:int, hidden:int, unhidden:int, errors:int}
     */
    public function refreshTopic(string $groupKey): array
    {
        $defaultLang = $this->defaultLang();

        $rows = Url::query()
            ->where('group_key', $groupKey)
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->where('lang', '!=', $defaultLang)
            ->get();

        if ($rows->isEmpty()) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 0];
        }

        $defaultRow = Url::query()
            ->where('group_key', $groupKey)
            ->where('lang', $defaultLang)
            ->first();

        if (! $defaultRow) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => $rows->count()];
        }

        [$defaultTitle, $defaultHeading] = $this->fetchTitle($defaultRow->source_url);

        if ($defaultTitle === null) {
            return ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => $rows->count()];
        }

        $autoHideEnabled = $this->settings->isAutoHideEnabled();
        $totals = ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 0];

        foreach ($rows as $row) {
            $this->mergeTotals($totals, $this->checkRow($row, $defaultTitle, $defaultHeading, $autoHideEnabled));
        }

        return $totals;
    }

    /**
     * How many non-default-language blog URLs are currently due for a check, so the settings
     * page can show real progress instead of leaving you guessing whether one batch run covered
     * everything.
     */
    public function pendingCount(): int
    {
        $cutoff = now()->subHours($this->settings->getRecheckIntervalHours());

        return $this->dueQuery($this->defaultLang(), $cutoff)->count();
    }

    private function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    private function dueQuery(string $defaultLang, ?Carbon $cutoff)
    {
        return Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->where('lang', '!=', $defaultLang)
            ->when($cutoff, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('translation_checked_at')->orWhere('translation_checked_at', '<=', $cutoff),
            ));
    }

    /**
     * @return array{checked:int, hidden:int, unhidden:int, errors:int}
     */
    private function checkRow(Url $row, string $defaultTitle, ?string $defaultHeading, bool $autoHideEnabled): array
    {
        $totals = ['checked' => 0, 'hidden' => 0, 'unhidden' => 0, 'errors' => 0];

        // An admin removed a hide we'd set, but never got a chance to record it because this row
        // wasn't due for a check yet. Back off for this run - just record the title-check result
        // and leave is_hidden alone - so the override actually holds for a cycle instead of being
        // immediately undone by the same run that noticed it.
        $wasOverridden = $row->auto_hidden_for_translation && ! $row->is_hidden;
        if ($wasOverridden) {
            $row->auto_hidden_for_translation = false;
        }

        [$title, $heading, $note] = $this->fetchTitle($row->source_url);
        $totals['checked']++;

        if ($title === null) {
            $totals['errors']++;
            $row->translation_check_note = $note;
            $row->save();

            return $totals;
        }

        $titleDiffers = $this->normalize($title) !== $this->normalize($defaultTitle);

        // Some pages on this site keep the <title> tag (and og:title/twitter:title) in the
        // default language even once the real, visible heading and body have genuinely been
        // translated - an SEO-title field apparently never filled in for that specific article,
        // not a real translation gap. Comparing the visible "article-title" heading too catches
        // those pages the title comparison alone would wrongly call "not translated". Only
        // trusted when both pages actually have a heading to compare - a missing heading on
        // either side (fetch hiccup, or a soft-404/catch-all page with no real article markup)
        // just falls back to the title-only signal instead of forcing a false "differs".
        $headingDiffers = ($heading !== null && $defaultHeading !== null)
            && $this->normalize($heading) !== $this->normalize($defaultHeading);

        $isTranslated = $titleDiffers || $headingDiffers;
        $translationCheckNote = $note;

        // A title difference from the English page alone isn't proof of a real translation - some
        // sites return HTTP 200 with a normal-looking catch-all/not-found page (in the target
        // language, so of course its title differs from English) for *any* nonexistent URL under
        // a language prefix, instead of a real 404. That would make this comparison say
        // "translated" for literally every guessed URL of that language, confirmed or not. Only
        // checked when the plain comparison above already says "translated" - skips the extra
        // probe request entirely for the common, correctly-detected "not translated" case.
        if ($isTranslated) {
            $scheme = parse_url($row->source_url, PHP_URL_SCHEME) ?: 'https';
            $host = parse_url($row->source_url, PHP_URL_HOST);
            $soft404Title = $host ? $this->soft404Title($scheme, $host, $row->lang) : null;

            if ($soft404Title !== null && $this->normalize($title) === $this->normalize($soft404Title)) {
                $isTranslated = false;
                $translationCheckNote = "Looks like this site's catch-all/not-found page for a missing {$row->lang} page, not a real translation (title: \"{$title}\")";
            }
        }

        $row->translation_title = $title;
        $row->is_translated = $isTranslated;
        $row->translation_checked_at = now();
        $row->translation_check_note = $translationCheckNote;

        if (! $wasOverridden) {
            if ($isTranslated) {
                if ($row->auto_hidden_for_translation) {
                    $row->is_hidden = false;
                    $row->auto_hidden_for_translation = false;
                    $totals['unhidden']++;
                }
            } elseif ($autoHideEnabled && ! $row->is_hidden) {
                $row->is_hidden = true;
                $row->auto_hidden_for_translation = true;
                $totals['hidden']++;
            }
        }

        $row->save();

        return $totals;
    }

    private function mergeTotals(array &$totals, array $delta): void
    {
        foreach ($delta as $key => $value) {
            $totals[$key] += $value;
        }
    }

    /**
     * @return array{0: ?string, 1: ?string, 2: ?string} [title, article heading, diagnostic
     * note]. Title is null whenever we couldn't get a trustworthy title to compare - a real
     * request failure, or a response that looks like a bot-check page rather than the actual
     * article - so the caller treats it as an error instead of feeding bad data into the
     * translated/untranslated comparison. Heading is separately null whenever the page has no
     * "article-title" element (soft-404/catch-all pages, or a fetch error) - callers fall back
     * to the title-only comparison in that case.
     */
    private function fetchTitle(string $url): array
    {
        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(10)->get($url);
        } catch (\Throwable $e) {
            // Broad catch deliberately: a single unreachable/erroring URL (dead link, blocked
            // host, timeout, malformed response, ...) should count as one error, not abort the
            // whole batch it's part of.
            return [null, null, 'Fetch error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return [null, null, 'HTTP '.$response->status()];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($response->body());
        libxml_clear_errors();

        $titleNodes = $doc->getElementsByTagName('title');

        if ($titleNodes->length === 0) {
            return [null, null, 'HTTP '.$response->status().', no <title> in response'];
        }

        $title = trim($titleNodes->item(0)->textContent);

        if ($title === '') {
            return [null, null, 'HTTP '.$response->status().', empty <title>'];
        }

        $normalized = $this->normalize($title);

        foreach (self::CHALLENGE_TITLE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return [null, null, "Looks like a bot-check page (title: \"{$title}\")"];
            }
        }

        $heading = $this->extractArticleHeading($doc);

        return [$title, $heading, 'HTTP '.$response->status().': "'.$title.'"'];
    }

    /**
     * Same "article-title" class BlogContentExtractionService reads the real article heading
     * from - see the ARTICLE_HEADING_CLASS comment above for why this exists. Null just means the
     * page doesn't have one (soft-404/catch-all pages don't), not that anything went wrong.
     */
    private function extractArticleHeading(\DOMDocument $doc): ?string
    {
        $xpath = new \DOMXPath($doc);
        $query = '//*[contains(concat(\' \', normalize-space(@class), \' \'), \' '.self::ARTICLE_HEADING_CLASS.' \')]';
        $nodes = $xpath->query($query);

        if (! $nodes || $nodes->length === 0) {
            return null;
        }

        $heading = trim(preg_replace('/\s+/', ' ', $nodes->item(0)->textContent));

        return $heading !== '' ? $heading : null;
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title)));
    }

    /**
     * Fetches the <title> of a URL that's essentially guaranteed not to be a real article
     * (a made-up slug under the given language prefix), to learn what this site's soft-404 looks
     * like, if it has one - a genuine HTTP 404 makes fetchTitle() return null here, same as for
     * any other request, so this is a no-op for a site that behaves normally. Memoized per
     * host+lang in $soft404TitleCache, so a batch run touching many rows of the same language only
     * probes once.
     */
    private function soft404Title(string $scheme, string $host, string $lang): ?string
    {
        $key = "{$scheme}://{$host}|{$lang}";

        if (! array_key_exists($key, $this->soft404TitleCache)) {
            $probeSlug = 'smmplus-soft-404-probe-'.substr(md5($host.'|'.$lang), 0, 12);
            $probeUrl = "{$scheme}://{$host}/{$lang}/blog/{$probeSlug}";
            [$title] = $this->fetchTitle($probeUrl);
            $this->soft404TitleCache[$key] = $title;
        }

        return $this->soft404TitleCache[$key];
    }
}
