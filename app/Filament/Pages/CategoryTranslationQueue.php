<?php

namespace App\Filament\Pages;

use App\Models\CategoryTranslation;
use App\Models\CategoryTranslationJob;
use App\Models\Language;
use App\Services\PanelNotificationService;
use App\Services\ServiceCatalogService;
use App\Services\SettingsService;
use App\Services\TranslationSettingsService;
use App\Support\PanelSection;
use App\Filament\Concerns\GuardsSectionEdits;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use ZipArchive;

/**
 * The category counterpart to ServiceTranslationQueue - same shape, one field only (a category
 * has just a name, no separate description), one row per category id per language instead of
 * per service. Categories are synced as a side effect of ServiceCatalogService::
 * syncDefaultCatalog()/refreshLanguage() (see that class) - "Sync now" here calls the exact same
 * methods the Service Translation page's own sync button does.
 */
class CategoryTranslationQueue extends Page implements HasActions
{
    use InteractsWithActions;
    use GuardsSectionEdits;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Category Translation';

    protected static string $view = 'filament.pages.category-translation-queue';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::TRANSLATION)) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(PanelNotificationService::class)->unreadCountForUrl(static::getUrl());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    // Visiting this page is itself the "I've seen it" signal for anything notifying about it
    // (translation-completed alerts) - clears both this nav badge and the bell's own count for
    // the same notifications together, rather than needing a separate dismiss action.
    public function mount(PanelNotificationService $notifications): void
    {
        $notifications->markUrlRead(static::getUrl());
    }

    public string $search = '';

    public string $statusFilter = 'missing';

    public int $queuePage = 1;

    // category_id values, not row ids - a category spans several category_translations rows (one
    // per language) and the bulk action below operates per-category, not per-row.
    public array $selectedCategories = [];

    public ?array $lastSyncResult = null;

    private const QUEUE_PER_PAGE = 20;

    public const STATUS_FILTERS = [
        'missing' => 'Has a missing language',
        'needsUpdate' => 'Needs to be uploaded to the site',
        'confirmed' => 'Fully uploaded',
        'all' => 'All categories',
    ];

    public function updatedSearch(): void
    {
        $this->queuePage = 1;
        $this->selectedCategories = [];
    }

    public function updatedStatusFilter(): void
    {
        $this->queuePage = 1;
        $this->selectedCategories = [];
    }

    public function previousQueuePage(): void
    {
        $this->queuePage = max(1, $this->queuePage - 1);
        $this->selectedCategories = [];
    }

    public function nextQueuePage(): void
    {
        $this->queuePage++;
        $this->selectedCategories = [];
    }

    public function toggleSelectAllOnPage(): void
    {
        $onPage = $this->queue['categories']
            ->reject(fn (array $c) => ! empty($c['pendingLangs']))
            ->pluck('row.category_id')
            ->all();
        $allSelected = empty(array_diff($onPage, $this->selectedCategories));

        $this->selectedCategories = $allSelected
            ? array_values(array_diff($this->selectedCategories, $onPage))
            : array_values(array_unique(array_merge($this->selectedCategories, $onPage)));
    }

    #[Computed]
    public function databaseReady(): bool
    {
        return $this->catalogTableAvailable();
    }

    /**
     * Categories sync as a side effect of the same hourly services:refresh-catalog run - reads
     * the same TranslationSettingsService progress the Service Translation page's own countdown
     * uses, since it's the same underlying schedule.
     *
     * @return array{hasRun: bool, percent: int, remainingMinutes: ?int, lastRunAt: ?\Illuminate\Support\Carbon}
     */
    #[Computed]
    public function cronProgress(): array
    {
        $settings = app(TranslationSettingsService::class);
        $lastRunAt = $settings->getServiceLastScheduledRunAt();
        $intervalMinutes = TranslationSettingsService::SERVICE_SCHEDULE_INTERVAL_MINUTES;

        if (! $lastRunAt) {
            return ['hasRun' => false, 'percent' => 0, 'remainingMinutes' => null, 'lastRunAt' => null];
        }

        $elapsedMinutes = min($intervalMinutes, max(0, abs(now()->diffInMinutes($lastRunAt))));

        return [
            'hasRun' => true,
            'percent' => (int) round(($elapsedMinutes / $intervalMinutes) * 100),
            'remainingMinutes' => (int) ceil(max(0, $intervalMinutes - $elapsedMinutes)),
            'lastRunAt' => $lastRunAt,
        ];
    }

    /**
     * @return array{categories: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function queue()
    {
        if (! $this->catalogTableAvailable()) {
            return ['categories' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0];
        }

        $defaultLang = $this->defaultLangCode();

        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $defaultQuery = CategoryTranslation::query()->where('lang', $defaultLang);

        if ($this->search !== '') {
            $defaultQuery->where(function ($query) {
                $query->where('title', 'like', "%{$this->search}%")
                    ->orWhere('category_id', 'like', "%{$this->search}%");
            });
        }

        $defaultRows = $defaultQuery->orderBy('title')->get();

        if ($defaultRows->isEmpty()) {
            return ['categories' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0];
        }

        $existingByKey = CategoryTranslation::query()
            ->whereIn('category_id', $defaultRows->pluck('category_id'))
            ->get()
            ->groupBy('category_id');

        $jobsByKey = $this->translationTrackingAvailable()
            ? CategoryTranslationJob::query()
                ->whereIn('category_id', $defaultRows->pluck('category_id'))
                ->whereIn('status', [...CategoryTranslationJob::PENDING_STATUSES, CategoryTranslationJob::FAILED])
                ->get()
                ->groupBy('category_id')
            : collect();

        $allCategories = $defaultRows->map(function (CategoryTranslation $defaultRow) use ($existingByKey, $activeLanguages, $jobsByKey) {
            $existingForKey = $existingByKey->get($defaultRow->category_id, collect())->keyBy('lang');
            $jobsForKey = $jobsByKey->get($defaultRow->category_id, collect());

            $pendingLangs = $jobsForKey->whereIn('status', CategoryTranslationJob::PENDING_STATUSES)->pluck('target_lang')->all();
            $failedLangs = $jobsForKey->where('status', CategoryTranslationJob::FAILED)->pluck('target_lang')->all();

            $languages = $activeLanguages->map(function (Language $language) use ($existingForKey, $pendingLangs, $failedLangs) {
                $row = $existingForKey->get($language->code);

                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'state' => $this->languageState($language, $row, $pendingLangs, $failedLangs),
                    'title' => $row?->title,
                    'retranslated' => $row?->wasRecentlyAutoRetranslated() ?? false,
                ];
            });

            return [
                'row' => $defaultRow,
                'languages' => $languages,
                'pendingLangs' => $pendingLangs,
            ];
        });

        $filtered = $allCategories->filter(function (array $category) {
            $nonDefault = $category['languages']->where('state', '!=', 'default');

            return $this->matchesStatusFilter($nonDefault, $this->statusFilter);
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::QUEUE_PER_PAGE));
        $page = max(1, min($this->queuePage, $lastPage));

        $categories = $filtered->slice(($page - 1) * self::QUEUE_PER_PAGE, self::QUEUE_PER_PAGE)->values();

        return ['categories' => $categories, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    /**
     * @param  \Illuminate\Support\Collection  $nonDefaultLanguages
     */
    private function matchesStatusFilter($nonDefaultLanguages, string $filter): bool
    {
        return match ($filter) {
            'missing' => $nonDefaultLanguages->contains(fn (array $l) => in_array($l['state'], ['missing', 'pending', 'error'], true)),
            'needsUpdate' => $nonDefaultLanguages->contains(fn (array $l) => in_array($l['state'], ['needsUpdate', 'pending'], true)),
            'confirmed' => $nonDefaultLanguages->isNotEmpty() && $nonDefaultLanguages->every(fn (array $l) => $l['state'] === 'confirmed'),
            default => true, // 'all'
        };
    }

    // Mirrors ServiceTranslationQueue::titleLanguageState().
    private function languageState(Language $language, ?CategoryTranslation $row, array $pendingLangs, array $failedLangs = []): string
    {
        if ($language->is_default) {
            return 'default';
        }

        if (in_array($language->code, $pendingLangs, true)) {
            return 'pending';
        }

        $looksTranslated = $row && $row->looksTranslated();

        if (! $looksTranslated && in_array($language->code, $failedLangs, true)) {
            return 'error';
        }

        if (! $looksTranslated) {
            return 'missing';
        }

        return $row->needsSiteUpdate() ? 'needsUpdate' : 'confirmed';
    }

    /**
     * Manual, immediate counterpart to the scheduled services:refresh-catalog command - calls the
     * exact same ServiceCatalogService methods the Service Translation page's own sync button
     * does, since categories sync as a side effect of the same fetch.
     */
    public function runSyncNow(ServiceCatalogService $catalog): void
    {
        if (! $this->assertCanEdit(PanelSection::TRANSLATION)) {
            return;
        }

        if (! $this->catalogTableAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        $sync = $catalog->syncDefaultCatalog();

        if (! $sync['ok']) {
            Notification::make()
                ->title('Could not sync the services catalog')
                ->body($sync['error'])
                ->danger()
                ->send();

            return;
        }

        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->pluck('code');

        $errors = 0;

        foreach ($activeLanguages as $langCode) {
            $result = $catalog->refreshLanguage($langCode);

            if (! $result['ok']) {
                $errors++;
            }
        }

        $this->lastSyncResult = ['errors' => $errors];

        unset($this->queue);

        Notification::make()
            ->title('Sync complete')
            ->body('Synced the catalog and re-checked category names across active languages.')
            ->success()
            ->send();
    }

    public function downloadCategoryExport(string $categoryId, array $langCodes): mixed
    {
        return $this->buildCategoryZip(
            [$categoryId => $langCodes],
            fn (CategoryTranslation $row) => $row->title,
            "category-export-{$categoryId}.zip",
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function translateCategory(string $categoryId, string $targetLangCode): array
    {
        if (! $this->assertCanEdit(PanelSection::TRANSLATION)) {
            return ['ok' => false, 'message' => 'No permission.'];
        }

        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return ['ok' => false, 'message' => 'Database update needed first.'];
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return ['ok' => false, 'message' => 'Panel update in progress.'];
        }

        if ($this->hasPendingJob($categoryId, $targetLangCode)) {
            Notification::make()
                ->title('Already translating')
                ->body('This language is already queued or in progress for this category.')
                ->warning()
                ->send();

            return ['ok' => true, 'message' => 'Already queued.'];
        }

        $this->queueTranslation($categoryId, $targetLangCode);

        unset($this->queue);

        Notification::make()
            ->title('Translation queued')
            ->body('Translating in the background - reopen this page in a minute to see it land.')
            ->success()
            ->send();

        return ['ok' => true, 'message' => 'Translation queued.'];
    }

    // Escape hatch for a job stuck showing "translating..." indefinitely (queued or running with
    // nothing actually left processing it - e.g. the queue command died mid-batch) - marks it
    // cancelled rather than deleting the row, so there's still a record of it, and immediately
    // frees the language up to be queued again. See ProcessCategoryTranslationQueueCommand's
    // conditional completion update for why a job already mid-flight in the same run won't get
    // silently un-cancelled if its AI response happens to land right after this.
    public function cancelTranslation(string $categoryId, string $targetLangCode): void
    {
        if (! $this->assertCanEdit(PanelSection::TRANSLATION)) {
            return;
        }

        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        $cancelled = CategoryTranslationJob::query()
            ->where('category_id', $categoryId)
            ->where('target_lang', $targetLangCode)
            ->whereIn('status', CategoryTranslationJob::PENDING_STATUSES)
            ->update(['status' => CategoryTranslationJob::CANCELLED]);

        unset($this->queue);

        Notification::make()
            ->title($cancelled > 0 ? 'Translation cancelled' : 'Nothing to cancel')
            ->body($cancelled > 0 ? 'You can queue it again anytime.' : 'This language wasn\'t queued or running.')
            ->success($cancelled > 0)
            ->warning($cancelled === 0)
            ->send();
    }

    public function translateAllMissingForCategory(string $categoryId): void
    {
        if (! $this->assertCanEdit(PanelSection::TRANSLATION)) {
            return;
        }

        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        $missingLanguages = $this->missingLanguagesFor($categoryId);

        if ($missingLanguages->isEmpty()) {
            Notification::make()->title('Nothing left to translate')->success()->send();

            return;
        }

        foreach ($missingLanguages as $langCode) {
            $this->queueTranslation($categoryId, $langCode);
        }

        unset($this->queue);

        Notification::make()
            ->title("Queued {$missingLanguages->count()} language(s) for translation")
            ->success()
            ->send();
    }

    public function queueMissingForSelected(): void
    {
        if (! $this->assertCanEdit(PanelSection::TRANSLATION)) {
            return;
        }

        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        if (empty($this->selectedCategories)) {
            Notification::make()->title('No categories selected')->warning()->send();

            return;
        }

        $totalQueued = 0;
        $categoriesAffected = 0;

        foreach ($this->selectedCategories as $categoryId) {
            $missingLanguages = $this->missingLanguagesFor($categoryId);

            foreach ($missingLanguages as $langCode) {
                $this->queueTranslation($categoryId, $langCode);
            }

            if ($missingLanguages->isNotEmpty()) {
                $totalQueued += $missingLanguages->count();
                $categoriesAffected++;
            }
        }

        $this->selectedCategories = [];
        unset($this->queue);

        if ($totalQueued === 0) {
            Notification::make()
                ->title('Nothing to queue')
                ->body('None of the selected categories have a missing language right now.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("Queued {$totalQueued} translation(s) across {$categoriesAffected} category/categories")
            ->success()
            ->send();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function missingLanguagesFor(string $categoryId): \Illuminate\Support\Collection
    {
        $existingByLang = CategoryTranslation::query()->where('category_id', $categoryId)->get()->keyBy('lang');

        $pendingLangs = CategoryTranslationJob::query()
            ->where('category_id', $categoryId)
            ->whereIn('status', CategoryTranslationJob::PENDING_STATUSES)
            ->pluck('target_lang')
            ->all();

        return Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->pluck('code')
            ->filter(function (string $code) use ($existingByLang, $pendingLangs) {
                if (in_array($code, $pendingLangs, true)) {
                    return false;
                }

                $row = $existingByLang->get($code);

                return ! $row || ! $row->looksTranslated();
            })
            ->values();
    }

    public function viewCategoryAction(): Action
    {
        return Action::make('viewCategory')
            ->label('Details')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->modalHeading(fn (array $arguments) => $arguments['title'] ?? 'Category details')
            ->modalWidth(MaxWidth::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view(
                'filament.pages.category-translation-details',
                $this->categoryDetails($arguments['categoryId']),
            ));
    }

    public function downloadSelectionAction(): Action
    {
        return Action::make('downloadSelection')
            ->label('Download names')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download category names for selected categories')
            ->modalWidth(MaxWidth::Medium)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.pages.category-translation-bulk-export', [
                'languages' => $this->activeNonDefaultLanguages(),
                'selectedCount' => count($this->selectedCategories),
                'wireMethod' => 'downloadSelectedCategoriesExport',
                'fileExample' => 'instagram/fa.txt',
                'fieldLabel' => 'category name',
            ]));
    }

    private function activeNonDefaultLanguages(): \Illuminate\Support\Collection
    {
        return Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->orderBy('sort_order')
            ->get(['code', 'name']);
    }

    public function downloadSelectedCategoriesExport(array $langCodes): mixed
    {
        if (empty($this->selectedCategories)) {
            Notification::make()->title('No categories selected')->warning()->send();

            return null;
        }

        $byCategory = collect($this->selectedCategories)->mapWithKeys(fn ($key) => [$key => $langCodes])->all();

        return $this->buildCategoryZip($byCategory, fn (CategoryTranslation $row) => $row->title, 'category-translations-selection.zip');
    }

    /**
     * @param  array<string, list<string>>  $langCodesByCategory
     */
    private function buildCategoryZip(array $langCodesByCategory, \Closure $valueResolver, string $downloadName): mixed
    {
        $langCodesByCategory = collect($langCodesByCategory)
            ->map(fn ($codes) => array_values(array_unique(array_filter($codes))))
            ->filter(fn ($codes) => ! empty($codes));

        if ($langCodesByCategory->isEmpty()) {
            Notification::make()->title('Select at least one language first')->warning()->send();

            return null;
        }

        $allLangCodes = $langCodesByCategory->flatten()->unique()->values()->all();

        $rows = CategoryTranslation::query()
            ->whereIn('category_id', $langCodesByCategory->keys())
            ->whereIn('lang', $allLangCodes)
            ->get();

        $zipPath = tempnam(sys_get_temp_dir(), 'category-export-').'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $added = 0;
        $singleCategory = $langCodesByCategory->count() === 1;

        foreach ($rows as $row) {
            $wantedLangs = $langCodesByCategory->get($row->category_id, []);

            if (! in_array($row->lang, $wantedLangs, true)) {
                continue;
            }

            $value = $valueResolver($row);

            if (blank($value)) {
                continue;
            }

            $filename = $singleCategory ? "{$row->lang}.txt" : "{$row->category_id}/{$row->lang}.txt";
            $zip->addFromString($filename, $value."\n");
            $added++;
        }

        if ($added === 0) {
            $zip->close();
            @unlink($zipPath);

            Notification::make()
                ->title('Nothing to export')
                ->body('None of the selected category/categories have content for the chosen languages.')
                ->warning()
                ->send();

            return null;
        }

        $zip->close();

        return response()->streamDownload(function () use ($zipPath) {
            readfile($zipPath);
            @unlink($zipPath);
        }, $downloadName, ['Content-Type' => 'application/zip']);
    }

    /**
     * @return array{languages: \Illuminate\Support\Collection, defaultLangCode: string, categoryId: string}
     */
    private function categoryDetails(string $categoryId): array
    {
        $defaultLangCode = $this->defaultLangCode();

        $rows = CategoryTranslation::query()->where('category_id', $categoryId)->get()->keyBy('lang');

        $languageDefs = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $jobs = $this->translationTrackingAvailable()
            ? CategoryTranslationJob::query()->where('category_id', $categoryId)->get()->keyBy('target_lang')
            : collect();

        $pendingLangs = $jobs->filter(fn (CategoryTranslationJob $job) => in_array($job->status, CategoryTranslationJob::PENDING_STATUSES, true))->keys()->all();

        $languages = $languageDefs->map(function (Language $language) use ($rows, $pendingLangs, $jobs) {
            /** @var ?CategoryTranslation $row */
            $row = $rows->get($language->code);

            return [
                'code' => $language->code,
                'name' => $language->name,
                'isDefault' => $language->is_default,
                'exists' => (bool) $row,
                'isTranslated' => $row !== null && $row->looksTranslated(),
                'needsSiteUpdate' => $row !== null && $row->needsSiteUpdate(),
                'title' => $row?->title,
                'checkedAt' => $row?->checked_at,
                'checkNote' => $row?->check_note,
                'pending' => in_array($language->code, $pendingLangs, true),
                'error' => $jobs->get($language->code)?->status === CategoryTranslationJob::FAILED
                    ? $jobs->get($language->code)->message
                    : null,
                'retranslated' => $row !== null && $row->wasRecentlyAutoRetranslated(),
            ];
        })->values();

        return [
            'languages' => $languages,
            'defaultLangCode' => $defaultLangCode,
            'categoryId' => $categoryId,
        ];
    }

    private function defaultLangCode(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    private function queueTranslation(string $categoryId, string $targetLangCode): void
    {
        CategoryTranslationJob::query()->updateOrCreate(
            ['category_id' => $categoryId, 'target_lang' => $targetLangCode],
            ['status' => CategoryTranslationJob::QUEUED, 'message' => null],
        );
    }

    private function hasPendingJob(string $categoryId, string $targetLangCode): bool
    {
        return CategoryTranslationJob::query()
            ->where('category_id', $categoryId)
            ->where('target_lang', $targetLangCode)
            ->whereIn('status', CategoryTranslationJob::PENDING_STATUSES)
            ->exists();
    }

    // Same reasoning as ServiceTranslationQueue's own translationTrackingAvailable() guard - an
    // admin without shell access has no way to run these tables' migrations except the "Update
    // database" button (General Settings).
    private static ?bool $translationTrackingAvailable = null;

    private function translationTrackingAvailable(): bool
    {
        return self::$translationTrackingAvailable ??= Schema::hasTable('category_translations')
            && Schema::hasTable('category_translation_jobs');
    }

    private function catalogTableAvailable(): bool
    {
        return $this->translationTrackingAvailable();
    }

    private function notifyDatabaseUpdateNeeded(): void
    {
        Notification::make()
            ->title('Database update needed')
            ->body('This feature needs a database update first - go to General Settings and click "Update database", then try again.')
            ->danger()
            ->send();
    }

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
}
