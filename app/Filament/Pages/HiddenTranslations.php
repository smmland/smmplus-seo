<?php

namespace App\Filament\Pages;

use App\Models\Url;
use App\Services\HiddenTranslationService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * A dedicated, detailed view of every translation SyncService has hidden (is_active = false) -
 * the Blog Translation Queue page already surfaces these via its "hidden" filter and a
 * dismiss-in-place banner, but this page exists for when the admin wants to see all of them at
 * once with when they were translated/last checked and their status, rather than hunting through
 * individual topics.
 */
class HiddenTranslations extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-eye-slash';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Hidden Translations';

    protected static ?int $navigationSort = 50;

    protected static string $view = 'filament.pages.hidden-translations';

    public string $search = '';

    public int $page = 1;

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

    public function reactivate(int $urlId): void
    {
        $row = Url::query()->find($urlId);

        if (! $row) {
            Notification::make()->title('Nothing to reactivate')->warning()->send();

            return;
        }

        app(HiddenTranslationService::class)->reactivate($row);

        unset($this->hiddenTranslations);

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

        Notification::make()
            ->title("Reactivated {$count} hidden translation(s)")
            ->body('They\'re visible again and won\'t be hidden by a sitemap sync anymore.')
            ->success()
            ->send();
    }
}
