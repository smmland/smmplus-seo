<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

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
            ->where('is_translated', true)
            ->where('lang', '!=', $this->defaultLangCode());
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
}
