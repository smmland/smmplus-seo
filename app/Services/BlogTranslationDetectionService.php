<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class BlogTranslationDetectionService
{
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

            $defaultTitle = $this->fetchTitle($defaultRow->source_url);

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

                $title = $this->fetchTitle($row->source_url);
                $checked++;

                if ($title === null) {
                    $errors++;
                    $row->save();

                    continue;
                }

                $isTranslated = $this->normalize($title) !== $this->normalize($defaultTitle);

                $row->translation_title = $title;
                $row->is_translated = $isTranslated;
                $row->translation_checked_at = now();

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

    private function fetchTitle(string $url): ?string
    {
        try {
            $response = Http::timeout(10)->get($url);
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($response->body());
        libxml_clear_errors();

        $titleNodes = $doc->getElementsByTagName('title');

        if ($titleNodes->length === 0) {
            return null;
        }

        $title = trim($titleNodes->item(0)->textContent);

        return $title !== '' ? $title : null;
    }

    private function normalize(string $title): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $title)));
    }
}
