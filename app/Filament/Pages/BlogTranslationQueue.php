<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\Url;
use App\Services\BlogContentExtractionService;
use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;

class BlogTranslationQueue extends Page implements HasActions
{
    use InteractsWithActions;

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

    public function recheckTopic(string $groupKey, BlogTranslationDetectionService $detector): void
    {
        $result = $detector->refreshTopic($groupKey);

        unset($this->queue);

        if ($result['checked'] === 0 && $result['errors'] > 0) {
            Notification::make()
                ->title('Could not check this topic')
                ->body('No reachable default-language page was found for it.')
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title('Recheck complete')
            ->body("Checked {$result['checked']}, hid {$result['hidden']}, unhid {$result['unhidden']}, errors {$result['errors']}.")
            ->success()
            ->send();
    }

    public function extractContent(int $urlId, BlogContentExtractionService $extractor): void
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            return;
        }

        $result = $extractor->extract($row);

        unset($this->queue);

        if (! $result['ok']) {
            Notification::make()
                ->title('Could not extract this page\'s content')
                ->body($result['error'])
                ->danger()
                ->send();

            return;
        }

        $titleNote = $result['articleTitle'] ? "Title: \"{$result['articleTitle']}\". " : '';

        Notification::make()
            ->title('Content extracted')
            ->body("{$titleNote}Images: {$result['imagesDownloaded']} downloaded ({$result['imagesInlined']} were base64 and got relinked), {$result['stylesConverted']} inline styles converted to classes. Title and SEO meta saved on the URL record.")
            ->success()
            ->actions([
                NotificationAction::make('preview')
                    ->label('Open preview')
                    ->url($result['previewUrl'], shouldOpenInNewTab: true),
                NotificationAction::make('content')
                    ->label('Open content file')
                    ->url($result['contentUrl'], shouldOpenInNewTab: true),
            ])
            ->send();
    }

    public function viewTopicAction(): Action
    {
        return Action::make('viewTopic')
            ->label('Details')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->modalHeading(fn (array $arguments) => $arguments['title'] ?? 'Topic details')
            ->modalWidth(MaxWidth::SevenExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view(
                'filament.pages.blog-translation-details',
                $this->topicDetails($arguments['groupKey']),
            ));
    }

    /**
     * @return array{englishRow: ?Url, languages: \Illuminate\Support\Collection, defaultLangCode: string}
     */
    private function topicDetails(string $groupKey): array
    {
        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $rows = Url::query()
            ->where('group_key', $groupKey)
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->get()
            ->keyBy('lang');

        $languageDefs = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $languages = $languageDefs->map(function (Language $language) use ($rows) {
            /** @var ?Url $row */
            $row = $rows->get($language->code);

            // Don't build a link from the slug/lang naming convention alone - rows extracted
            // before this file-naming scheme existed (or under a slug that's since changed)
            // would otherwise get a link to a preview file that doesn't actually exist, which
            // 404s in the iframe below. Only link to it once it's confirmed to be on disk.
            $previewPath = ($row && $row->content_extraction_path)
                ? 'blog/'.$row->slug.'/preview-'.$row->lang.'.html'
                : null;
            $previewExists = $previewPath && Storage::disk('public')->exists($previewPath);

            return [
                'code' => $language->code,
                'name' => $language->name,
                'isDefault' => $language->is_default,
                'exists' => (bool) $row,
                'isTranslated' => $row?->is_translated === true,
                'sourceUrl' => $row?->source_url,
                'urlId' => $row?->id,
                'articleTitle' => $row?->article_title,
                'seoTitle' => $row?->seo_title,
                'metaDescription' => $row?->meta_description,
                'contentExtracted' => $previewExists,
                'previewUrl' => $previewExists ? url('/blog-content/'.$previewPath) : null,
            ];
        })->values();

        return [
            'englishRow' => $rows->get($defaultLangCode),
            'languages' => $languages,
            'defaultLangCode' => $defaultLangCode,
        ];
    }

    /**
     * How far along we are toward the next automatic hourly recheck of the next batch of blog
     * URLs, so the queue page can show it as a progress bar instead of leaving it a mystery.
     *
     * @return array{hasRun: bool, percent: int, remainingMinutes: ?int, lastRunAt: ?\Illuminate\Support\Carbon}
     */
    #[Computed]
    public function cronProgress(): array
    {
        $settings = app(TranslationSettingsService::class);
        $lastRunAt = $settings->getLastScheduledRunAt();
        $intervalMinutes = TranslationSettingsService::SCHEDULE_INTERVAL_MINUTES;

        if (! $lastRunAt) {
            return ['hasRun' => false, 'percent' => 0, 'remainingMinutes' => null, 'lastRunAt' => null];
        }

        // abs() because Carbon's diffInMinutes() sign convention isn't reliable across versions
        // for "which side is later" - we only care about magnitude here.
        $elapsedMinutes = min($intervalMinutes, max(0, abs(now()->diffInMinutes($lastRunAt))));

        return [
            'hasRun' => true,
            'percent' => (int) round(($elapsedMinutes / $intervalMinutes) * 100),
            'remainingMinutes' => (int) ceil(max(0, $intervalMinutes - $elapsedMinutes)),
            'lastRunAt' => $lastRunAt,
        ];
    }
}
