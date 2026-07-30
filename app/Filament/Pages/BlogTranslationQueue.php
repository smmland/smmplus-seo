<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\Url;
use App\Services\BlogContentExtractionService;
use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
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
