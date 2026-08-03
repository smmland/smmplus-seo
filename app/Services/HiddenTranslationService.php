<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Recovery for translations SyncService has hidden (is_active = false, see the is_ai_guessed
 * exemption on that class) because their guessed URL wasn't in the real sitemap. Shared between
 * the Blog Translation Queue's inline "hidden" filter/banner and the standalone Hidden
 * Translations list, so both agree on exactly what counts as hidden and how reactivating one
 * works, instead of drifting apart as separate copies of the same query.
 */
class HiddenTranslationService
{
    public function defaultLangCode(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    public function query(): Builder
    {
        return Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', false)
            ->where('lang', '!=', $this->defaultLangCode())
            ->where($this->looksTranslatedClause());
    }

    // Matches Url::looksTranslated() as a query - is_translated OR real extracted content, not
    // is_translated alone. A row from a site that was already multi-language before this admin
    // ever used AI translation here (discovered by the normal sitemap sync, then just had
    // "Extract content" run on it) never gets is_translated set at all, so restricting to that
    // column alone would silently skip it from every list on this page, same bug as before.
    private function looksTranslatedClause(): \Closure
    {
        return function ($query) {
            $query->where('is_translated', true)->orWhereNotNull('content_extraction_path');
        };
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Brings back one hidden row - never deleted, only ever flagged is_active = false, so this
     * just flips it back. Also marks it is_ai_guessed (in case it predates that column) so a
     * future sync can't hide it again.
     */
    public function reactivate(Url $row): void
    {
        $row->is_active = true;

        if (Schema::hasColumn('urls', 'is_ai_guessed')) {
            $row->is_ai_guessed = true;
        }

        $row->save();
    }

    /**
     * @return int how many rows were reactivated
     */
    public function reactivateAll(): int
    {
        $query = $this->query();
        $count = $query->count();

        if ($count === 0) {
            return 0;
        }

        $updates = ['is_active' => true];

        if (Schema::hasColumn('urls', 'is_ai_guessed')) {
            $updates['is_ai_guessed'] = true;
        }

        $query->update($updates);

        return $count;
    }

    /**
     * A second, unrelated way a translation can become invisible in every list, is_active aside:
     * group_key for a blog post is always "blog:{slug}" (UrlClassifierService), fixed on the
     * translation row at the moment it was created (BlogAiTranslationService::saveTranslation()).
     * If the original article's own slug/URL later changes (renamed on the live site) or its
     * default-language row stops being active, the default-language sync keeps recalculating
     * *that* row's own group_key/is_active - but nothing ever goes back and updates the
     * translation rows that were keyed to its old group_key. Every list here (including "hidden"
     * above) is built by walking active default-language topics outward to their translations, so
     * a row stuck on a group_key with no matching active default row is never reached by any of
     * them - translated, not deleted, just orphaned from the topic it was translated for.
     */
    public function orphanedQuery(): Builder
    {
        $defaultLang = $this->defaultLangCode();

        $activeGroupKeys = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', $defaultLang)
            ->where('is_active', true)
            ->pluck('group_key');

        return Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', '!=', $defaultLang)
            ->where($this->looksTranslatedClause())
            ->whereNotIn('group_key', $activeGroupKeys);
    }

    public function orphanedCount(): int
    {
        return $this->orphanedQuery()->count();
    }

    // Matches "content_extraction_path is set, but is_translated was never explicitly set true" -
    // whereNull is needed alongside where(false): SQL's != true doesn't match NULL, and a never-
    // checked row's is_translated is NULL, not false.
    private function unflaggedContentQuery(): Builder
    {
        return Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', '!=', $this->defaultLangCode())
            ->whereNotNull('content_extraction_path')
            ->where(function ($query) {
                $query->whereNull('is_translated')->orWhere('is_translated', false);
            });
    }

    public function unflaggedContentCount(): int
    {
        return $this->unflaggedContentQuery()->count();
    }

    /**
     * Backfills is_translated=true for every row that already has real extracted content but was
     * never explicitly flagged - the data-level fix behind Url::looksTranslated() (which patches
     * the same gap at read time everywhere it's checked, but leaves the underlying column
     * inconsistent forever unless this actually runs). Safe to run any number of times: only ever
     * touches rows matching unflaggedContentQuery(), which shrinks to nothing once they're fixed.
     *
     * @return int how many rows were fixed
     */
    public function fixUnflaggedContent(): int
    {
        $query = $this->unflaggedContentQuery();
        $count = $query->count();

        if ($count > 0) {
            $query->update(['is_translated' => true]);
        }

        return $count;
    }

    /**
     * A third, more severe way a translation can be invisible everywhere: hidden and orphaned
     * above both still have a database row to find (is_active=false, or a stale group_key) - this
     * finds translated content files on disk (storage/app/public/blog/{slug}/content-{lang}.html)
     * with no Url row at all for that slug+lang, in any state. saveTranslation() always writes
     * the database row before the content file, so this shouldn't happen from a translation made
     * through this tool working normally - but deleteTranslation()'s file cleanup targets paths
     * built from the row's *current* slug, so if a topic was ever recategorized (slug changed)
     * between when a translation was made and when it was deleted, that cleanup silently misses
     * the file saved under the old slug while still deleting the row - leaving exactly this: a
     * real, readable translated file with nothing in the database pointing at it anymore.
     *
     * @return array<int, array{slug: string, lang: string, path: string, size: int, modifiedAt: \Illuminate\Support\Carbon}>
     */
    public function scanForFilesWithNoRow(): array
    {
        $disk = Storage::disk('public');
        $defaultLang = $this->defaultLangCode();
        $found = [];

        if (! $disk->exists('blog')) {
            return $found;
        }

        foreach ($disk->directories('blog') as $slugDir) {
            $slug = basename($slugDir);

            foreach ($disk->files($slugDir) as $file) {
                if (! preg_match('/^content-([a-z]{2,3})\.html$/', basename($file), $m)) {
                    continue;
                }

                $lang = $m[1];

                if ($lang === $defaultLang) {
                    continue;
                }

                $exists = Url::query()
                    ->where('group_key', "blog:{$slug}")
                    ->where('lang', $lang)
                    ->exists();

                if ($exists) {
                    continue;
                }

                $found[] = [
                    'slug' => $slug,
                    'lang' => $lang,
                    'path' => $file,
                    'size' => $disk->size($file),
                    'modifiedAt' => Carbon::createFromTimestamp($disk->lastModified($file)),
                ];
            }
        }

        return $found;
    }

    /**
     * Recreates a Url row for a file scanForFilesWithNoRow() found, from the file itself - the
     * content is real and complete, only the database record pointing at it was ever missing.
     * The recovered row starts as "translated, not yet confirmed live" (needsSiteUpdate() reads
     * true from the null translation_checked_at, same as any other fresh translation) rather than
     * guessing at its real status, since there's no record of when or whether it was ever
     * actually confirmed against the live site.
     */
    public function recoverFileAsRow(string $slug, string $lang): Url
    {
        $groupKey = "blog:{$slug}";
        $defaultLang = $this->defaultLangCode();

        $englishRow = Url::query()
            ->where('group_key', $groupKey)
            ->where('lang', $defaultLang)
            ->first();

        $scheme = $englishRow ? (parse_url($englishRow->source_url, PHP_URL_SCHEME) ?: 'https') : 'https';
        $host = $englishRow ? parse_url($englishRow->source_url, PHP_URL_HOST) : null;

        if (! $host) {
            $anyRow = Url::query()->whereNotNull('source_url')->first();
            $host = $anyRow ? parse_url($anyRow->source_url, PHP_URL_HOST) : null;
        }

        $sourceUrl = $host ? "{$scheme}://{$host}/{$lang}/blog/{$slug}" : "https://unknown-host/{$lang}/blog/{$slug}";

        $contentPath = "blog/{$slug}/content-{$lang}.html";
        $previewPath = "blog/{$slug}/preview-{$lang}.html";

        // The content file itself is just the article body (no <title> tag to read) - but the
        // preview file wrapping it is a full document with one, built from whatever title was on
        // record at translation time (BlogAiTranslationService::saveTranslation()).
        $articleTitle = null;
        if (Storage::disk('public')->exists($previewPath)) {
            $previewHtml = Storage::disk('public')->get($previewPath);
            if (preg_match('/<title>(.*?)\s*-\s*preview<\/title>/s', $previewHtml, $m)) {
                $articleTitle = html_entity_decode(trim($m[1]));
            }
        }

        $attributes = [
            'source_url' => $sourceUrl,
            'path' => "/{$lang}/blog/{$slug}",
            'pattern_type' => 'BLOG',
            'slug' => $slug,
            'is_active' => true,
            'is_translated' => true,
            'article_title' => $articleTitle,
            'content_extraction_path' => $contentPath,
            'content_extracted_at' => Storage::disk('public')->lastModified($contentPath)
                ? Carbon::createFromTimestamp(Storage::disk('public')->lastModified($contentPath))
                : now(),
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];

        if (Schema::hasColumn('urls', 'is_ai_guessed')) {
            $attributes['is_ai_guessed'] = true;
        }

        return Url::query()->create(array_merge(['group_key' => $groupKey, 'lang' => $lang], $attributes));
    }
}
