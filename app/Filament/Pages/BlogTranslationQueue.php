<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\Url;
use App\Services\BlogAiTranslationService;
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

    /**
     * Saves the manually-edited version of a URL's content, separate from the original
     * extracted content - both are kept, so editing never loses the original to compare
     * against. Also (re)writes a standalone preview file for the edited version, matching how
     * the original's preview works, so both are equally copyable/previewable.
     */
    public function saveEditedContent(int $urlId, string $html): void
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            return;
        }

        // A rough sanity cap - this is meant for one blog post's body, not an arbitrary upload.
        if (strlen($html) > 2_000_000) {
            Notification::make()
                ->title('Content too large to save')
                ->danger()
                ->send();

            return;
        }

        $row->edited_content = $html;
        $row->edited_content_saved_at = now();
        $row->save();

        $baseDir = 'blog/'.$row->slug;
        $previewTitle = e($row->article_title ?? $row->slug);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="{$row->lang}">
            <head>
            <meta charset="utf-8">
            <title>{$previewTitle} - edited preview</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="mx-auto max-w-3xl px-6 py-10">
            <h1 class="text-3xl font-bold mb-6">{$previewTitle}</h1>
            {$html}
            </body>
            </html>
            HTML;

        Storage::disk('public')->put("{$baseDir}/edited-preview-{$row->lang}.html", $previewHtml);

        unset($this->queue);

        Notification::make()
            ->title('Edited content saved')
            ->success()
            ->send();
    }

    /**
     * Stores an image uploaded from the visual editor (a new image, or a replacement for an
     * existing one) as a real file, the same way extraction does - keeps saveEditedContent()'s
     * HTML small (a URL, not a data: URI) and gives the image a stable, previewable link.
     */
    public function uploadEditedImage(int $urlId, string $dataUrl): ?string
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            return null;
        }

        if (! preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $dataUrl, $m)) {
            return null;
        }

        $bytes = base64_decode($m[2], true);

        if ($bytes === false || strlen($bytes) > 8_000_000) {
            return null;
        }

        $ext = match (strtolower($m[1])) {
            'jpeg' => 'jpg',
            'svg+xml' => 'svg',
            default => preg_replace('/[^a-z0-9]/i', '', strtolower($m[1])) ?: 'jpg',
        };

        $filename = substr(md5($m[2]), 0, 16).'-'.now()->timestamp.'.'.$ext;
        $path = "blog/{$row->slug}/images/edited/{$filename}";

        Storage::disk('public')->put($path, $bytes);

        return url('/blog-content/'.$path);
    }

    /**
     * Translates the topic's default-language content into one specific missing language -
     * works whether or not that language already has a Url row (a real page might not exist on
     * the live site yet), since BlogAiTranslationService creates one if needed.
     */
    public function translateLanguage(string $groupKey, string $targetLangCode, BlogAiTranslationService $translator): void
    {
        $sourceRow = $this->defaultLanguageRow($groupKey);

        if (! $sourceRow) {
            Notification::make()
                ->title('Could not find the default-language content for this topic')
                ->danger()
                ->send();

            return;
        }

        $result = $translator->translate($sourceRow, $targetLangCode);

        unset($this->queue);

        $notification = Notification::make()
            ->title($result['ok'] ? 'Translated' : 'Translation failed')
            ->body($result['message']);

        $result['ok'] ? $notification->success() : $notification->danger();

        $notification->send();
    }

    /**
     * The quick-access version on the default-language tab: translates whichever missing
     * language comes first, one at a time - a single AI translation call can already run close
     * to this shared host's PHP execution limit, so working through a large backlog is done by
     * clicking again (same pattern as the "Run check now" batch button), not in one shot.
     */
    public function translateNextMissingLanguage(string $groupKey, BlogAiTranslationService $translator): void
    {
        $sourceRow = $this->defaultLanguageRow($groupKey);

        if (! $sourceRow) {
            Notification::make()
                ->title('Could not find the default-language content for this topic')
                ->danger()
                ->send();

            return;
        }

        $existingByLang = Url::query()
            ->where('group_key', $groupKey)
            ->where('is_active', true)
            ->get()
            ->keyBy('lang');

        $missingLanguage = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->first(function (Language $language) use ($existingByLang) {
                $row = $existingByLang->get($language->code);

                return ! $row || $row->is_translated !== true;
            });

        if (! $missingLanguage) {
            Notification::make()
                ->title('Nothing left to translate')
                ->body('Every active language already has a translation for this topic.')
                ->success()
                ->send();

            return;
        }

        $result = $translator->translate($sourceRow, $missingLanguage->code);

        unset($this->queue);

        $notification = Notification::make()
            ->title($result['ok'] ? "Translated into {$missingLanguage->name}" : 'Translation failed')
            ->body($result['message']);

        $result['ok'] ? $notification->success() : $notification->danger();

        $notification->send();
    }

    private function defaultLanguageRow(string $groupKey): ?Url
    {
        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        return Url::query()
            ->where('group_key', $groupKey)
            ->where('lang', $defaultLangCode)
            ->first();
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
            $contentPath = ($row && $row->content_extraction_path)
                ? 'blog/'.$row->slug.'/content-'.$row->lang.'.html'
                : null;
            $previewPath = $contentPath ? 'blog/'.$row->slug.'/preview-'.$row->lang.'.html' : null;
            $previewExists = $previewPath && Storage::disk('public')->exists($previewPath);
            $contentHtml = ($contentPath && $previewExists) ? Storage::disk('public')->get($contentPath) : null;

            $editedPreviewPath = $row ? 'blog/'.$row->slug.'/edited-preview-'.$row->lang.'.html' : null;
            $editedPreviewExists = $editedPreviewPath && Storage::disk('public')->exists($editedPreviewPath);
            // Passed to the editor regardless of whether the file exists yet, so it can start
            // showing the "Preview edited" link the moment Save succeeds - Alpine's local state
            // otherwise has no way to learn the file now exists without the modal being reopened
            // (Livewire morphs preserve already-mounted x-data instead of re-running it).
            $editedPreviewUrlTemplate = $editedPreviewPath ? url('/blog-content/'.$editedPreviewPath) : null;

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
                'metaKeywords' => $row?->meta_keywords,
                'ogTitle' => $row?->og_title,
                'ogDescription' => $row?->og_description,
                'twitterTitle' => $row?->twitter_title,
                'twitterDescription' => $row?->twitter_description,
                'contentExtracted' => $previewExists,
                'previewUrl' => $previewExists ? url('/blog-content/'.$previewPath) : null,
                'contentHtml' => $contentHtml,
                'editedContent' => $row?->edited_content,
                'editedContentSavedAt' => $row?->edited_content_saved_at,
                'editedPreviewUrl' => $editedPreviewExists ? url('/blog-content/'.$editedPreviewPath) : null,
                'editedPreviewUrlTemplate' => $editedPreviewUrlTemplate,
            ];
        })->values();

        return [
            'englishRow' => $rows->get($defaultLangCode),
            'languages' => $languages,
            'defaultLangCode' => $defaultLangCode,
            'groupKey' => $groupKey,
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
