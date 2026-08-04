<?php

namespace App\Filament\Pages;

use App\Models\Url;
use App\Services\HiddenTranslationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;

/**
 * A dedicated, detailed view of every translation that isn't showing up correctly in the normal
 * Blog Translation Queue list, for several unrelated reasons:
 *
 * - Hidden: SyncService flagged it is_active = false because its guessed URL wasn't in the real
 *   sitemap. Never deleted, just not shown - see HiddenTranslationService::query().
 * - Orphaned: its group_key ("blog:{old-slug}") no longer matches any currently-active
 *   default-language topic, almost always because the original article was renamed/removed on
 *   the live site after this translation was made. Also never deleted, but there's no "topic" for
 *   it to attach back to automatically - see HiddenTranslationService::orphanedQuery().
 * - No database record: a translated file exists on disk with no Url row at all - see
 *   HiddenTranslationService::scanForFilesWithNoRow().
 * - Unflagged content: a Url row and its extracted content both exist and are perfectly fine, but
 *   is_translated was never explicitly set (a page that was already multi-language before this
 *   admin ever used AI translation, only ever "Extract content"'d, not translated or Recheck'd
 *   through this tool) - see HiddenTranslationService::unflaggedContentQuery().
 */
class HiddenTranslations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Hidden Translations';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.hidden-translations';

    // Reached from Translation Settings' "Advanced tools" section instead of the sidebar - a
    // recovery tool for a handful of edge cases, not something that needs its own permanent spot
    // in the main nav that every admin sees on every page load.
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public string $search = '';

    public int $page = 1;

    public string $orphanSearch = '';

    public int $orphanPage = 1;

    private const PER_PAGE = 20;

    public function updatedSearch(): void
    {
        $this->page = 1;
    }

    public function previousPage(): void
    {
        $this->page = max(1, $this->page - 1);
    }

    public function nextPage(): void
    {
        $this->page++;
    }

    public function updatedOrphanSearch(): void
    {
        $this->orphanPage = 1;
    }

    public function previousOrphanPage(): void
    {
        $this->orphanPage = max(1, $this->orphanPage - 1);
    }

    public function nextOrphanPage(): void
    {
        $this->orphanPage++;
    }

    #[Computed]
    public function unflaggedContentCount(): int
    {
        return app(HiddenTranslationService::class)->unflaggedContentCount();
    }

    /**
     * A row from a site that was already multi-language before this admin ever used AI
     * translation here - discovered normally by SyncService, then just had "Extract content" run
     * on it - never gets is_translated set (only an actual translation attempt or a live-site
     * check sets it), so it reads as "missing" everywhere despite its content being right there.
     * Url::looksTranslated() already patches this at read time, but this actually fixes the
     * underlying data so it's correct everywhere, including outside this panel's own queries.
     */
    public function fixUnflaggedContent(): void
    {
        $count = app(HiddenTranslationService::class)->fixUnflaggedContent();

        unset($this->unflaggedContentCount);
        unset($this->hiddenTranslations);
        unset($this->orphanedTranslations);

        if ($count === 0) {
            Notification::make()->title('Nothing to fix')->warning()->send();

            return;
        }

        Notification::make()
            ->title("Fixed {$count} row(s)")
            ->body('These already had real translated content, just never got flagged - they now show up correctly in Blog Translation Queue.')
            ->success()
            ->send();
    }

    /**
     * @return array{items: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function hiddenTranslations(): array
    {
        $hidden = app(HiddenTranslationService::class);
        $query = $hidden->query();

        if ($this->search !== '') {
            $query->where(function ($q) {
                $q->where('slug', 'like', "%{$this->search}%")
                    ->orWhere('article_title', 'like', "%{$this->search}%")
                    ->orWhere('source_url', 'like', "%{$this->search}%");
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($this->page, $lastPage));

        // Most recently touched first - whether that's a live-check confirming/denying it or, if
        // it was never checked at all, just when the row was originally translated.
        $rows = $query->orderByRaw('COALESCE(translation_checked_at, last_seen_at) desc')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $englishRows = Url::query()
            ->where('pattern_type', 'BLOG')
            ->where('lang', $hidden->defaultLangCode())
            ->whereIn('group_key', $rows->pluck('group_key'))
            ->get(['group_key', 'slug', 'article_title', 'source_url'])
            ->keyBy('group_key');

        $items = $rows->map(fn (Url $row) => [
            'row' => $row,
            'englishRow' => $englishRows->get($row->group_key),
            'needsSiteUpdate' => $row->needsSiteUpdate(),
        ]);

        return ['items' => $items, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    /**
     * @return array{items: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function orphanedTranslations(): array
    {
        $hidden = app(HiddenTranslationService::class);
        $query = $hidden->orphanedQuery();

        if ($this->orphanSearch !== '') {
            $query->where(function ($q) {
                $q->where('slug', 'like', "%{$this->orphanSearch}%")
                    ->orWhere('article_title', 'like', "%{$this->orphanSearch}%")
                    ->orWhere('source_url', 'like', "%{$this->orphanSearch}%");
            });
        }

        $total = (clone $query)->count();
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        $page = max(1, min($this->orphanPage, $lastPage));

        $rows = $query->orderByRaw('COALESCE(translation_checked_at, last_seen_at) desc')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $items = $rows->map(function (Url $row) {
            $previewPath = "blog/{$row->slug}/preview-{$row->lang}.html";
            $editedPreviewPath = "blog/{$row->slug}/edited-preview-{$row->lang}.html";

            return [
                'row' => $row,
                'previewUrl' => Storage::disk('public')->exists($editedPreviewPath)
                    ? url('/blog-content/'.$editedPreviewPath)
                    : (Storage::disk('public')->exists($previewPath) ? url('/blog-content/'.$previewPath) : null),
            ];
        });

        return ['items' => $items, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    public function reactivate(int $urlId): void
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            Notification::make()->title('Nothing to reactivate')->warning()->send();

            return;
        }

        app(HiddenTranslationService::class)->reactivate($row);

        unset($this->hiddenTranslations);
        unset($this->orphanedTranslations);

        Notification::make()
            ->title('Reactivated')
            ->body('This translation is visible again and won\'t be hidden by a sitemap sync anymore.')
            ->success()
            ->send();
    }

    public function reactivateAll(): void
    {
        $count = app(HiddenTranslationService::class)->reactivateAll();

        if ($count === 0) {
            Notification::make()->title('Nothing to reactivate')->warning()->send();

            return;
        }

        $this->page = 1;
        unset($this->hiddenTranslations);
        unset($this->orphanedTranslations);

        Notification::make()
            ->title("Reactivated {$count} hidden translation(s)")
            ->body('They\'re visible again and won\'t be hidden by a sitemap sync anymore.')
            ->success()
            ->send();
    }

    /**
     * For an orphaned row specifically - there's no topic left for it to reattach to
     * automatically, so once the admin has opened its preview and copied whatever they still
     * needed from it, this clears it out. Mirrors BlogTranslationQueue::deleteTranslation()'s own
     * cleanup (content/preview files plus the row itself).
     */
    public function deleteOrphaned(int $urlId): void
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            return;
        }

        $baseDir = "blog/{$row->slug}";
        Storage::disk('public')->delete([
            "{$baseDir}/content-{$row->lang}.html",
            "{$baseDir}/preview-{$row->lang}.html",
            "{$baseDir}/edited-preview-{$row->lang}.html",
        ]);

        $row->delete();

        unset($this->orphanedTranslations);

        Notification::make()
            ->title('Deleted')
            ->success()
            ->send();
    }

    // null until the admin explicitly runs the scan - walking every blog/{slug}/ directory on
    // disk is more work than the two queries above, so this only happens on demand rather than on
    // every page load/poll.
    public ?array $diskScanResults = null;

    public function scanDisk(): void
    {
        $this->diskScanResults = app(HiddenTranslationService::class)->scanForFilesWithNoRow();

        Notification::make()
            ->title(count($this->diskScanResults) > 0
                ? count($this->diskScanResults).' file(s) found with no database record'
                : 'Nothing found')
            ->body(count($this->diskScanResults) > 0
                ? 'These are real translated files on disk - recover each one to bring it back into every list, or delete it if you don\'t need it.'
                : 'Every translated file on disk already has a matching database record.')
            ->success()
            ->send();
    }

    /**
     * Turns one scan result back into a real, working Url row (HiddenTranslationService::
     * recoverFileAsRow()) - after this, the topic shows up normally everywhere (Blog Translation
     * Queue, and this page's own Hidden/Orphaned sections if it turns out to need either).
     */
    public function recoverFromDisk(string $slug, string $lang): void
    {
        app(HiddenTranslationService::class)->recoverFileAsRow($slug, $lang);

        $this->diskScanResults = collect($this->diskScanResults)
            ->reject(fn (array $r) => $r['slug'] === $slug && $r['lang'] === $lang)
            ->values()
            ->all();

        unset($this->hiddenTranslations);
        unset($this->orphanedTranslations);

        Notification::make()
            ->title('Recovered')
            ->body('A database record now exists for this file - it shows up in Blog Translation Queue as needing a site-update confirmation, since there\'s no record of whether it was ever actually live.')
            ->success()
            ->send();
    }

    /**
     * For a scan result the admin has decided not to recover (e.g. genuinely stale content from
     * an article that's long gone) - deletes just the file, since there's no database row to go
     * with it.
     */
    public function deleteDiskFile(string $slug, string $lang): void
    {
        $baseDir = "blog/{$slug}";
        Storage::disk('public')->delete([
            "{$baseDir}/content-{$lang}.html",
            "{$baseDir}/preview-{$lang}.html",
            "{$baseDir}/edited-preview-{$lang}.html",
        ]);

        $this->diskScanResults = collect($this->diskScanResults)
            ->reject(fn (array $r) => $r['slug'] === $slug && $r['lang'] === $lang)
            ->values()
            ->all();

        Notification::make()
            ->title('Deleted')
            ->success()
            ->send();
    }
}
