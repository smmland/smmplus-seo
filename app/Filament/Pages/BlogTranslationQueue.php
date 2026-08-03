<?php

namespace App\Filament\Pages;

use App\Models\BlogTranslationJob;
use App\Models\Language;
use App\Models\Url;
use App\Services\BlogContentExtractionService;
use App\Services\BlogTranslationDetectionService;
use App\Services\SettingsService;
use App\Services\TranslationSettingsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Actions\Action as NotificationAction;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use ZipArchive;

class BlogTranslationQueue extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Blog Translation';

    protected static string $view = 'filament.pages.blog-translation-queue';

    public string $search = '';

    public int $queuePage = 1;

    // Every blog topic is shown here (not just ones missing something) - the admin asked for a
    // single browsable list they can search/paginate to find and re-translate any topic, not
    // only ones currently flagged as needing attention.
    private const QUEUE_PER_PAGE = 20;

    public function updatedSearch(): void
    {
        $this->queuePage = 1;
    }

    public function previousQueuePage(): void
    {
        $this->queuePage = max(1, $this->queuePage - 1);
    }

    public function nextQueuePage(): void
    {
        $this->queuePage++;
    }

    /**
     * @return array{topics: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function queue()
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        // All active languages including the default one - the unified status column shows every
        // language's state for a topic, default included (as a distinct "source language" badge),
        // rather than needing a separate column just for that.
        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $englishQuery = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->where('lang', $defaultLang);

        if ($this->search !== '') {
            $englishQuery->where(function ($query) {
                $query->where('slug', 'like', "%{$this->search}%")
                    ->orWhere('article_title', 'like', "%{$this->search}%")
                    ->orWhere('source_url', 'like', "%{$this->search}%");
            });
        }

        $total = (clone $englishQuery)->count();
        $lastPage = max(1, (int) ceil($total / self::QUEUE_PER_PAGE));
        $page = max(1, min($this->queuePage, $lastPage));

        $englishRows = $englishQuery->orderBy('source_url')->forPage($page, self::QUEUE_PER_PAGE)->get();

        if ($englishRows->isEmpty()) {
            return ['topics' => collect(), 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
        }

        $existingByGroup = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->whereIn('group_key', $englishRows->pluck('group_key'))
            ->get()
            ->groupBy('group_key');

        $pendingByGroup = $this->translationTrackingAvailable()
            ? BlogTranslationJob::query()
                ->whereIn('group_key', $englishRows->pluck('group_key'))
                ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
                ->get()
                ->groupBy('group_key')
            : collect();

        $topics = $englishRows->map(function (Url $englishRow) use ($existingByGroup, $activeLanguages, $pendingByGroup) {
            $existingForGroup = $existingByGroup->get($englishRow->group_key, collect())->keyBy('lang');
            $pendingLangs = $pendingByGroup->get($englishRow->group_key, collect())->pluck('target_lang')->all();

            $languages = $activeLanguages->map(function (Language $language) use ($existingForGroup, $pendingLangs) {
                $row = $existingForGroup->get($language->code);

                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'state' => $this->languageState($language, $row, $pendingLangs),
                ];
            });

            return [
                'url' => $englishRow,
                'languages' => $languages,
                'pendingLangs' => $pendingLangs,
            ];
        })->values();

        return ['topics' => $topics, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    /**
     * One of: default (the source language), pending (queued/running), missing (no row, or
     * exists but never translated), needsUpdate (translated but not confirmed live -
     * needsSiteUpdate()), confirmed (translated and confirmed live). Mirrors the tab-icon states
     * already used in the topic details popup, just computed once here for the list view too.
     */
    private function languageState(Language $language, ?Url $row, array $pendingLangs): string
    {
        if ($language->is_default) {
            return 'default';
        }

        if (in_array($language->code, $pendingLangs, true)) {
            return 'pending';
        }

        if (! $row || $row->is_translated !== true) {
            return 'missing';
        }

        return $this->needsSiteUpdate($row) ? 'needsUpdate' : 'confirmed';
    }

    /**
     * True when a language is marked translated (Url::is_translated) but that's never actually
     * been confirmed against the live site, or was confirmed before the content it's based on
     * last changed - covering real gaps in is_translated on its own: BlogTranslationDetectionService's
     * hourly recheck leaves is_translated untouched on a fetch failure (translation_checked_at
     * stays at its old value) - which includes the common case of the guessed target URL simply
     * not existing live yet (a 404), not just transient errors.
     *
     * translation_checked_at alone isn't quite enough to trust either: BlogAiTranslationService
     * used to set it itself the instant a translation was generated (fixed, but topics
     * translated before that fix still carry the stale value it left behind - there was never a
     * migration to undo it retroactively). translation_title is the tell: a genuine live check
     * (checkRow()) always sets it in the same write as translation_checked_at, so a row with the
     * latter but not the former was never actually fetched and confirmed - old bug or not.
     */
    private function needsSiteUpdate(Url $row): bool
    {
        // An admin's own call always wins - added because comparing just the fetched <title>
        // can false-positive as "confirmed live" on a soft-404 (a site returning HTTP 200 with
        // some other title instead of a real 404 for a page that doesn't actually exist yet),
        // which the automatic checks below can't fully rule out for every site. See
        // toggleSiteUpdateOverride().
        if ($row->site_update_override === true) {
            return true;
        }

        if ($row->is_translated !== true) {
            return false;
        }

        if ($row->translation_checked_at === null || $row->translation_title === null) {
            return true;
        }

        return $row->content_extracted_at !== null && $row->content_extracted_at->gt($row->translation_checked_at);
    }

    // Flips the manual override for one language - set true if it wasn't already overridden
    // (forcing "needs site update" regardless of what the automatic check concluded), or cleared
    // if it was, handing the verdict back to the automatic check. A fresh AI translation also
    // clears it (see queueTranslation()), so overriding never gets stuck hiding real progress.
    public function toggleSiteUpdateOverride(string $groupKey, string $targetLangCode): void
    {
        if (! Schema::hasColumn('urls', 'site_update_override')) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        $row = Url::query()->where('group_key', $groupKey)->where('lang', $targetLangCode)->first();

        if (! $row) {
            return;
        }

        $row->site_update_override = ! $row->site_update_override;
        $row->save();

        unset($this->queue);

        Notification::make()
            ->title($row->site_update_override ? 'Marked as needing a site update' : 'Override cleared')
            ->body($row->site_update_override
                ? 'This language will show as needing an update regardless of what the automatic check found, until you clear this or retranslate it.'
                : 'Handed back to the automatic live check.')
            ->success()
            ->send();
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

    /**
     * The "missing" counterpart to recheckTopic()/the per-language "Recheck now" button - both
     * of those need an existing Url row to update, but a language never translated in this tool
     * has none. Lets the admin check anyway: if it's actually live (translated outside this
     * tool entirely, e.g. edited directly in the site's own CMS), this creates the row so the
     * panel picks it up as confirmed - see BlogTranslationDetectionService::checkMissingLanguage().
     */
    public function recheckMissingLanguage(string $groupKey, string $targetLangCode, BlogTranslationDetectionService $detector): void
    {
        $result = $detector->checkMissingLanguage($groupKey, $targetLangCode);

        unset($this->queue);

        $notification = Notification::make()
            ->title($result['ok'] ? 'Found live' : 'Not translated yet')
            ->body($result['message']);

        $result['ok'] ? $notification->success() : $notification->warning();

        $notification->send();
    }

    /**
     * Returns the fresh content (not just void + a notification) because this is called both
     * from a plain wire:click (the queue table row, which ignores the return value) and from the
     * visual/code editor's own JS via $wire.call() - the editor's Alpine state was already
     * initialized once from the old content when the modal first mounted, and a Livewire re-render
     * alone won't refresh it (morphs preserve already-mounted x-data instead of re-running it), so
     * the caller needs the fresh HTML handed back directly to update it client-side.
     *
     * @return array{ok: bool, error?: string, contentHtml?: string}
     */
    public function extractContent(int $urlId, BlogContentExtractionService $extractor): array
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            return ['ok' => false, 'error' => 'URL not found.'];
        }

        $result = $extractor->extract($row);

        unset($this->queue);

        if (! $result['ok']) {
            Notification::make()
                ->title('Could not extract this page\'s content')
                ->body($result['error'])
                ->danger()
                ->send();

            return ['ok' => false, 'error' => $result['error']];
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

        return ['ok' => true, 'contentHtml' => Storage::disk('public')->get($result['contentPath']) ?? ''];
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
        $dir = Language::direction($row->lang);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="{$row->lang}" dir="{$dir}">
            <head>
            <meta charset="utf-8">
            <title>{$previewTitle} - edited preview</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="mx-auto max-w-3xl px-6 py-10" dir="{$dir}">
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
     * Queues the topic's default-language content for translation into one specific missing
     * language - works whether or not that language already has a Url row (a real page might
     * not exist on the live site yet), since BlogAiTranslationService::saveTranslation() creates
     * one if needed.
     *
     * Processed off the scheduled queue (translation:process-queue, routes/console.php) rather
     * than inline in this request: a large article can legitimately take several minutes for the
     * AI to translate well, which is both longer than this shared host's PHP execution limit and
     * long enough that blocking the admin's browser on it would be a bad experience regardless.
     *
     * @return array{ok: bool, message: string}
     */
    public function translateLanguage(string $groupKey, string $targetLangCode): array
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return ['ok' => false, 'message' => 'Database update needed first.'];
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return ['ok' => false, 'message' => 'Panel update in progress.'];
        }

        $sourceRow = $this->defaultLanguageRow($groupKey);

        if (! $sourceRow) {
            Notification::make()
                ->title('Could not find the default-language content for this topic')
                ->danger()
                ->send();

            return ['ok' => false, 'message' => 'Could not find the default-language content for this topic.'];
        }

        if ($this->hasPendingTranslation($groupKey, $targetLangCode)) {
            Notification::make()
                ->title('Already translating')
                ->body('This language is already queued or in progress - no need to queue it again.')
                ->warning()
                ->send();

            return ['ok' => true, 'message' => 'Already queued.'];
        }

        $this->queueTranslation($groupKey, $targetLangCode);

        unset($this->queue);

        Notification::make()
            ->title('Translation queued')
            ->body('Translating in the background - this can take a few minutes for a long article. Reopen this tab once it\'s done.')
            ->success()
            ->send();

        return ['ok' => true, 'message' => 'Translation queued.'];
    }

    /**
     * The quick-access version on the default-language tab: queues whichever missing language
     * comes first. Click again for the next one once it's done - same "work through the backlog
     * a step at a time" pattern as the "Run check now" batch button elsewhere.
     */
    public function translateNextMissingLanguage(string $groupKey): void
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

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

        $pendingLangs = BlogTranslationJob::query()
            ->where('group_key', $groupKey)
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->pluck('target_lang')
            ->all();

        $missingLanguage = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->first(function (Language $language) use ($existingByLang, $pendingLangs) {
                if (in_array($language->code, $pendingLangs, true)) {
                    return false;
                }

                $row = $existingByLang->get($language->code);

                return ! $row || $row->is_translated !== true;
            });

        if (! $missingLanguage) {
            Notification::make()
                ->title($pendingLangs ? 'Already translating' : 'Nothing left to translate')
                ->body($pendingLangs
                    ? 'The remaining missing language(s) are already queued or in progress.'
                    : 'Every active language already has a translation for this topic.')
                ->success()
                ->send();

            return;
        }

        $this->queueTranslation($groupKey, $missingLanguage->code);

        unset($this->queue);

        Notification::make()
            ->title("Queued translation into {$missingLanguage->name}")
            ->body('Translating in the background - this can take a few minutes for a long article.')
            ->success()
            ->send();
    }

    /**
     * Queues every currently-missing language for this topic at once, instead of one at a time -
     * the scheduled queue processor (translation:process-queue) then works through all of them,
     * up to the configured concurrency (AI Settings: Max concurrent translations). Every queued
     * language immediately becomes "pending", which is what actually blocks re-translating any of
     * this topic's languages until the batch finishes - hasPendingTranslation() (used by
     * translateLanguage()) and the "missing" check below both already treat a pending language as
     * unavailable to queue again, so no separate topic-level lock is needed on top of that.
     */
    public function translateAllMissingLanguages(string $groupKey): void
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

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

        $pendingLangs = BlogTranslationJob::query()
            ->where('group_key', $groupKey)
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->pluck('target_lang')
            ->all();

        $missingLanguages = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->filter(function (Language $language) use ($existingByLang, $pendingLangs) {
                if (in_array($language->code, $pendingLangs, true)) {
                    return false;
                }

                $row = $existingByLang->get($language->code);

                return ! $row || $row->is_translated !== true;
            });

        if ($missingLanguages->isEmpty()) {
            Notification::make()
                ->title($pendingLangs ? 'Already translating' : 'Nothing left to translate')
                ->body($pendingLangs
                    ? 'The remaining missing language(s) are already queued or in progress.'
                    : 'Every active language already has a translation for this topic.')
                ->success()
                ->send();

            return;
        }

        foreach ($missingLanguages as $language) {
            $this->queueTranslation($groupKey, $language->code);
        }

        unset($this->queue);

        $count = $missingLanguages->count();

        Notification::make()
            ->title("Queued {$count} language(s) for translation")
            ->body('Translating in the background, up to a few at a time - this can take a while for many languages. Progress shows above while it runs.')
            ->success()
            ->send();
    }

    /**
     * Wipes one language's translation for this topic entirely - title, SEO meta, and the
     * translated content/preview files - reverting it to "no page exists yet" so it can be
     * translated fresh. Never the default language: that's the source everything else
     * translates from, not a translation itself.
     */
    public function deleteTranslation(string $groupKey, string $targetLangCode): void
    {
        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        if ($targetLangCode === $defaultLangCode) {
            Notification::make()
                ->title('Cannot delete the default language')
                ->danger()
                ->send();

            return;
        }

        $row = Url::query()->where('group_key', $groupKey)->where('lang', $targetLangCode)->first();

        if (! $row) {
            Notification::make()
                ->title('Nothing to delete')
                ->body('No translation exists for this language.')
                ->warning()
                ->send();

            return;
        }

        $languageName = Language::query()->where('code', $targetLangCode)->value('name') ?? $targetLangCode;

        $baseDir = "blog/{$row->slug}";
        Storage::disk('public')->delete([
            "{$baseDir}/content-{$targetLangCode}.html",
            "{$baseDir}/preview-{$targetLangCode}.html",
            "{$baseDir}/edited-preview-{$targetLangCode}.html",
        ]);

        $row->delete();

        if ($this->translationTrackingAvailable()) {
            BlogTranslationJob::query()->where('group_key', $groupKey)->where('target_lang', $targetLangCode)->delete();
        }

        unset($this->queue);

        Notification::make()
            ->title('Translation deleted')
            ->body("All {$languageName} content for this topic has been removed.")
            ->success()
            ->send();
    }

    /**
     * Builds a downloadable zip for the checked languages: one {lang}.html per language (its
     * final content - the manually edited version if one exists, else the raw
     * AI-extracted/translated HTML) plus a single meta_seo.txt aggregating each selected
     * language's page title, meta keywords, and meta description in the same [lang]-block
     * format used for manual SEO handoff elsewhere.
     */
    public function downloadSeoExport(string $groupKey, array $langCodes): mixed
    {
        $langCodes = array_values(array_unique(array_filter($langCodes)));

        if (empty($langCodes)) {
            Notification::make()
                ->title('Select at least one language first')
                ->warning()
                ->send();

            return null;
        }

        $rows = Url::query()
            ->where('group_key', $groupKey)
            ->where('pattern_type', 'BLOG')
            ->where('is_active', true)
            ->whereIn('lang', $langCodes)
            ->get()
            ->keyBy('lang');

        $zipPath = tempnam(sys_get_temp_dir(), 'seo-export-').'.zip';

        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $metaBlocks = [];

        // Same [code] / label / blank-line layout the admin already hands off manually - keeping
        // it identical means this can drop straight into whatever process already consumes that
        // format, instead of needing its own new parser.
        foreach ($langCodes as $code) {
            $row = $rows->get($code);

            if (! $row) {
                continue;
            }

            $zip->addFromString("{$code}.html", $this->exportableContentHtml($row));

            $metaBlocks[] = implode("\n", [
                "[{$code}]",
                'Page title:',
                $row->seo_title ?? '',
                '',
                'Meta-keywords:',
                $row->meta_keywords ?? '',
                '',
                'Meta-description:',
                $row->meta_description ?? '',
            ]);
        }

        if (empty($metaBlocks)) {
            $zip->close();
            @unlink($zipPath);

            Notification::make()
                ->title('Nothing to export')
                ->body('None of the selected languages have any content for this topic.')
                ->warning()
                ->send();

            return null;
        }

        $zip->addFromString('meta_seo.txt', implode("\n\n---\n\n", $metaBlocks)."\n");
        $zip->close();

        return response()->streamDownload(function () use ($zipPath) {
            readfile($zipPath);
            @unlink($zipPath);
        }, "seo-export-{$groupKey}.zip", ['Content-Type' => 'application/zip']);
    }

    private function exportableContentHtml(Url $row): string
    {
        if (filled($row->edited_content)) {
            return $row->edited_content;
        }

        $contentPath = "blog/{$row->slug}/content-{$row->lang}.html";

        return Storage::disk('public')->exists($contentPath)
            ? Storage::disk('public')->get($contentPath)
            : '';
    }

    // Queuing is just this row - no Laravel ShouldQueue job to dispatch. The scheduled
    // translation:process-queue command (routes/console.php) picks up every QUEUED row directly
    // each cron tick, resolving the source row itself from group_key + the default language, so
    // there's nothing else this needs to hand off.
    private function queueTranslation(string $groupKey, string $targetLangCode): void
    {
        BlogTranslationJob::query()->updateOrCreate(
            ['group_key' => $groupKey, 'target_lang' => $targetLangCode],
            ['status' => BlogTranslationJob::QUEUED, 'message' => null],
        );
    }

    private function hasPendingTranslation(string $groupKey, string $targetLangCode): bool
    {
        return BlogTranslationJob::query()
            ->where('group_key', $groupKey)
            ->where('target_lang', $targetLangCode)
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->exists();
    }

    // Guards every blog_translation_jobs query in this class - an admin without shell access
    // has no way to run that table's migration except the "Update database" button (General
    // Settings), so there's a real window after deploying this feature, before they've clicked
    // it, where the table genuinely doesn't exist yet. Without this guard, just opening the
    // topic details modal (topicDetails() below) would hard-crash the whole page instead of
    // degrading to "translation status unavailable, everything else still works". Cached per
    // request - Schema::hasTable() is a real query, and this gets checked on every render/poll.
    private static ?bool $translationTrackingAvailable = null;

    private function translationTrackingAvailable(): bool
    {
        return self::$translationTrackingAvailable ??= Schema::hasTable('blog_translation_jobs');
    }

    private function notifyDatabaseUpdateNeeded(): void
    {
        Notification::make()
            ->title('Database update needed')
            ->body('This feature needs a database update first - go to General Settings and click "Update database", then try again.')
            ->danger()
            ->send();
    }

    // A panel update overwrites this app's own files mid-request - starting a new background
    // translation while that's happening risks the job running against a half-swapped codebase.
    // Returns true (and shows why) so callers can bail out the same way they do for
    // translationTrackingAvailable().
    private function notifyIfPanelUpdateInProgress(): bool
    {
        if (! app(SettingsService::class)->isPanelUpdateInProgress()) {
            return false;
        }

        Notification::make()
            ->title('Panel update in progress')
            ->body('A file update is being installed right now - try again in a minute once it\'s done.')
            ->warning()
            ->send();

        return true;
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

        $translationJobs = $this->translationTrackingAvailable()
            ? BlogTranslationJob::query()->where('group_key', $groupKey)->get()->keyBy('target_lang')
            : collect();

        $pendingLangs = $translationJobs
            ->filter(fn (BlogTranslationJob $job) => in_array($job->status, BlogTranslationJob::PENDING_STATUSES, true))
            ->keys()
            ->all();

        $languages = $languageDefs->map(function (Language $language) use ($rows, $pendingLangs, $translationJobs) {
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
                'needsSiteUpdate' => $row && $this->needsSiteUpdate($row),
                'siteUpdateOverride' => $row?->site_update_override === true,
                'translationCheckedAt' => $row?->translation_checked_at,
                'translationCheckNote' => $row?->translation_check_note,
                'translationTitle' => $row?->translation_title,
                'translationPending' => in_array($language->code, $pendingLangs, true),
                'translationError' => $translationJobs->get($language->code)?->status === BlogTranslationJob::FAILED
                    ? $translationJobs->get($language->code)->message
                    : null,
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
