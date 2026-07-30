<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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

    public function __construct(private readonly TranslationSettingsService $settings) {}

    /**
     * Re-fetches the live <title> for every non-default-language blog URL that's due for a
     * check, compares it against its topic's default-language (English) title, and - only for
     * rows we're already in control of - hides/unhides based on the result.
     *
     * $limit bounds how many URLs get fetched in one call, most-overdue first - this runs on a
     * host with no queue workers and a real PHP execution time limit, so an unbounded fetch loop
     * (one HTTP request per URL) is a genuine timeout risk once there are more than a couple
     * dozen blog URLs. A large backlog just gets worked off across several runs instead.
     *
     * @return array{checked:int, hidden:int, unhidden:int, errors:int}
     */
    public function refresh(bool $force = false, int $limit = 200): array
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';
        $autoHideEnabled = $this->settings->isAutoHideEnabled();
        $cutoff = now()->subHours($this->settings->getRecheckIntervalHours());

        $candidates = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->where('lang', '!=', $defaultLang)
            ->when(! $force, fn ($q) => $q->where(
                fn ($q) => $q->whereNull('translation_checked_at')->orWhere('translation_checked_at', '<=', $cutoff),
            ))
            ->orderByRaw('translation_checked_at is not null')
            ->orderBy('translation_checked_at')
            ->limit($limit)
            ->get()
            ->groupBy('group_key');

        $checked = 0;
        $hidden = 0;
        $unhidden = 0;
        $errors = 0;

        foreach ($candidates as $groupKey => $rows) {
            $defaultRow = Url::query()
                ->where('group_key', $groupKey)
                ->where('lang', $defaultLang)
                ->first();

            if (! $defaultRow) {
                continue;
            }

            [$defaultTitle, $defaultNote] = $this->fetchTitle($defaultRow->source_url);

            if ($defaultTitle === null) {
                $errors += $rows->count();

                continue;
            }

            foreach ($rows as $row) {
                // An admin removed a hide we'd set, but never got a chance to record it because
                // this row wasn't due for a check yet. Back off for this run - just record the
                // title-check result and leave is_hidden alone - so the override actually holds
                // for a cycle instead of being immediately undone by the same run that noticed it.
                $wasOverridden = $row->auto_hidden_for_translation && ! $row->is_hidden;
                if ($wasOverridden) {
                    $row->auto_hidden_for_translation = false;
                }

                [$title, $note] = $this->fetchTitle($row->source_url);
                $checked++;

                if ($title === null) {
                    $errors++;
                    $row->translation_check_note = $note;
                    $row->save();

                    continue;
                }

                $isTranslated = $this->normalize($title) !== $this->normalize($defaultTitle);

                $row->translation_title = $title;
                $row->is_translated = $isTranslated;
                $row->translation_checked_at = now();
                $row->translation_check_note = $note ?? $defaultNote;

                if (! $wasOverridden) {
                    if ($isTranslated) {
                        if ($row->auto_hidden_for_translation) {
                            $row->is_hidden = false;
                            $row->auto_hidden_for_translation = false;
                            $unhidden++;
                        }
                    } elseif ($autoHideEnabled && ! $row->is_hidden) {
                        $row->is_hidden = true;
                        $row->auto_hidden_for_translation = true;
                        $hidden++;
                    }
                }

                $row->save();
            }
        }

        return compact('checked', 'hidden', 'unhidden', 'errors');
    }

    /**
     * @return array{0: ?string, 1: ?string} [title, diagnostic note]. Title is null whenever we
     * couldn't get a trustworthy title to compare - a real request failure, or a response that
     * looks like a bot-check page rather than the actual article - so the caller treats it as an
     * error instead of feeding bad data into the translated/untranslated comparison.
     */
    private function fetchTitle(string $url): array
    {
        try {
            $response = Http::withHeaders(self::FETCH_HEADERS)->timeout(10)->get($url);
        } catch (ConnectionException $e) {
            return [null, 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return [null, 'HTTP '.$response->status()];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($response->body());
        libxml_clear_errors();

        $titleNodes = $doc->getElementsByTagName('title');

        if ($titleNodes->length === 0) {
            return [null, 'HTTP '.$response->status().', no <title> in response'];
        }

        $title = trim($titleNodes->item(0)->textContent);

        if ($title === '') {
            return [null, 'HTTP '.$response->status().', empty <title>'];
        }

        $normalized = $this->normalize($title);

        foreach (self::CHALLENGE_TITLE_MARKERS as $marker) {
            if (str_contains($normalized, $marker)) {
                return [null, "Looks like a bot-check page (title: \"{$title}\")"];
            }
        }

        return [$title, 'HTTP '.$response->status().': "'.$title.'"'];
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title)));
    }
}
