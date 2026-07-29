<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\Url;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class BlogTranslationQueue extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Blog Translation';

    protected static string $view = 'filament.pages.blog-translation-queue';

    #[Computed]
    public function queue()
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->orderBy('sort_order')
            ->get(['code', 'name']);

        $englishRows = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->where('lang', $defaultLang)
            ->orderBy('source_url')
            ->get();

        if ($englishRows->isEmpty()) {
            return collect();
        }

        $existingByGroup = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->whereIn('group_key', $englishRows->pluck('group_key'))
            ->get()
            ->groupBy('group_key');

        return $englishRows
            ->map(function (Url $englishRow) use ($existingByGroup, $activeLanguages) {
                $existingForGroup = $existingByGroup->get($englishRow->group_key, collect())->keyBy('lang');

                $missing = $activeLanguages->filter(function (Language $language) use ($existingForGroup) {
                    $row = $existingForGroup->get($language->code);

                    return ! $row || $row->is_translated !== true;
                });

                return [
                    'url' => $englishRow,
                    'missing' => $missing,
                ];
            })
            ->filter(fn (array $topic) => $topic['missing']->isNotEmpty())
            ->values();
    }
}
