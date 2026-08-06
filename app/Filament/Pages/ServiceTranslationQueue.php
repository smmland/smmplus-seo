<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Services\ServiceCatalogService;
use App\Services\SettingsService;
use App\Services\TranslationSettingsService;
use App\Support\PanelSection;
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
 * The services counterpart to BlogTranslationQueue - structurally simpler, since every service
 * (across every language) lives on one shared listing page rather than one URL per item, so
 * there's no per-row "extract content" step and no separate "confirmed live on the real site" vs
 * "translated by us" distinction: ServiceCatalogService::refreshLanguage() IS the live check,
 * comparing each language's actual page directly.
 *
 * Description and title are tracked, translated, and downloaded as two fully independent
 * pipelines throughout this page - see ServiceTranslationJob::FIELD_DESCRIPTION/FIELD_TITLE and
 * ServiceTranslation's separate is_translated/is_title_translated columns. Every action here
 * comes in a matching pair (translateLanguage()/translateTitle(),
 * downloadServiceExport()/downloadServiceTitleExport(), ...) rather than one action taking a
 * "which field" parameter, so each stays simple and neither can be mixed up with the other.
 */
class ServiceTranslationQueue extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Service Translation';

    protected static string $view = 'filament.pages.service-translation-queue';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::TRANSLATION) ?? false;
    }

    public string $search = '';

    public string $statusFilter = 'missing';

    // Some services genuinely have no description on the site itself (a category header row
    // that slipped through, or a service the source page just never filled in) - there's nothing
    // to translate for those, so this lets them be filtered out of the list entirely.
    public bool $hasDescriptionOnly = false;

    public int $queuePage = 1;

    // service_key values, not row ids - a service spans several service_translations rows (one
    // per language) and the bulk action below operates per-service, not per-row.
    public array $selectedServices = [];

    public ?array $lastSyncResult = null;

    private const QUEUE_PER_PAGE = 20;

    // Filters description status only - title has its own badges/actions throughout this page,
    // but not a second copy of this dropdown (the list is already filtered down to what needs
    // attention on the description side, which is what most workflows here start from).
    public const STATUS_FILTERS = [
        'missing' => 'Has a missing language',
        'needsUpdate' => 'Needs to be uploaded to the site',
        'confirmed' => 'Fully uploaded',
        'all' => 'All services',
    ];

    public function updatedSearch(): void
    {
        $this->queuePage = 1;
        $this->selectedServices = [];
    }

    public function updatedStatusFilter(): void
    {
        $this->queuePage = 1;
        $this->selectedServices = [];
    }

    public function updatedHasDescriptionOnly(): void
    {
        $this->queuePage = 1;
        $this->selectedServices = [];
    }

    public function previousQueuePage(): void
    {
        $this->queuePage = max(1, $this->queuePage - 1);
        $this->selectedServices = [];
    }

    public function nextQueuePage(): void
    {
        $this->queuePage++;
        $this->selectedServices = [];
    }

    public function toggleSelectAllOnPage(): void
    {
        $onPage = $this->queue['services']
            ->reject(fn (array $s) => ! empty($s['pendingLangs']))
            ->pluck('row.service_key')
            ->all();
        $allSelected = empty(array_diff($onPage, $this->selectedServices));

        $this->selectedServices = $allSelected
            ? array_values(array_diff($this->selectedServices, $onPage))
            : array_values(array_unique(array_merge($this->selectedServices, $onPage)));
    }

    // Lets the view distinguish "database update needed" from "synced, but genuinely nothing
    // found yet" without querying the model directly itself - a raw Eloquent call in the blade
    // would hit the exact same missing-table crash this page's own queue() guards against.
    #[Computed]
    public function databaseReady(): bool
    {
        return $this->catalogTableAvailable();
    }

    /**
     * How far along we are toward the next automatic hourly services:refresh-catalog run - the
     * services counterpart to BlogTranslationQueue::cronProgress(), same shape, reading
     * TranslationSettingsService's service-specific pair of methods instead.
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
     * @return array{services: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function queue()
    {
        // Both service_translations and service_translation_jobs are brand-new tables - an admin
        // without shell access has no way to create them except the "Update database" button
        // (General Settings), so there's a real window after installing this feature, before
        // they've clicked it, where the tables genuinely don't exist yet. Without this guard,
        // just opening this page would hard-crash it instead of degrading to a clear message.
        if (! $this->catalogTableAvailable()) {
            return ['services' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0];
        }

        $defaultLang = $this->defaultLangCode();

        $activeLanguages = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $defaultQuery = ServiceTranslation::query()->where('lang', $defaultLang);

        if ($this->search !== '') {
            $defaultQuery->where(function ($query) {
                $query->where('title', 'like', "%{$this->search}%")
                    ->orWhere('category_title', 'like', "%{$this->search}%")
                    ->orWhere('service_key', 'like', "%{$this->search}%");
            });
        }

        if ($this->hasDescriptionOnly) {
            $defaultQuery->whereNotNull('description')->where('description', '!=', '');
        }

        $defaultRows = $defaultQuery
            ->orderBy('category_title')
            ->orderBy('title')
            ->get();

        if ($defaultRows->isEmpty()) {
            return ['services' => collect(), 'page' => 1, 'lastPage' => 1, 'total' => 0];
        }

        $existingByKey = ServiceTranslation::query()
            ->whereIn('service_key', $defaultRows->pluck('service_key'))
            ->get()
            ->groupBy('service_key');

        $jobsByKey = $this->translationTrackingAvailable()
            ? ServiceTranslationJob::query()
                ->whereIn('service_key', $defaultRows->pluck('service_key'))
                ->whereIn('status', [...ServiceTranslationJob::PENDING_STATUSES, ServiceTranslationJob::FAILED])
                ->get()
                ->groupBy('service_key')
            : collect();

        $allServices = $defaultRows->map(function (ServiceTranslation $defaultRow) use ($existingByKey, $activeLanguages, $jobsByKey) {
            $existingForKey = $existingByKey->get($defaultRow->service_key, collect())->keyBy('lang');
            $jobsForKey = $jobsByKey->get($defaultRow->service_key, collect());

            $descJobs = $jobsForKey->where('field', ServiceTranslationJob::FIELD_DESCRIPTION);
            $titleJobs = $jobsForKey->where('field', ServiceTranslationJob::FIELD_TITLE);

            $pendingLangs = $descJobs->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)->pluck('target_lang')->all();
            $failedLangs = $descJobs->where('status', ServiceTranslationJob::FAILED)->pluck('target_lang')->all();
            $pendingTitleLangs = $titleJobs->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)->pluck('target_lang')->all();
            $failedTitleLangs = $titleJobs->where('status', ServiceTranslationJob::FAILED)->pluck('target_lang')->all();

            $languages = $activeLanguages->map(function (Language $language) use ($existingForKey, $pendingLangs, $failedLangs, $pendingTitleLangs, $failedTitleLangs) {
                $row = $existingForKey->get($language->code);

                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'state' => $this->descriptionLanguageState($language, $row, $pendingLangs, $failedLangs),
                    'titleState' => $this->titleLanguageState($language, $row, $pendingTitleLangs, $failedTitleLangs),
                    'description' => $row?->description,
                    'retranslated' => $row?->wasRecentlyAutoRetranslatedDescription() ?? false,
                    'titleRetranslated' => $row?->wasRecentlyAutoRetranslatedTitle() ?? false,
                ];
            });

            return [
                'row' => $defaultRow,
                'languages' => $languages,
                'pendingLangs' => $pendingLangs,
                'pendingTitleLangs' => $pendingTitleLangs,
            ];
        });

        $filtered = $allServices->filter(function (array $service) {
            $nonDefault = $service['languages']->where('state', '!=', 'default');

            return match ($this->statusFilter) {
                'missing' => $nonDefault->contains(fn (array $l) => in_array($l['state'], ['missing', 'pending', 'error'], true)),
                'needsUpdate' => $nonDefault->contains(fn (array $l) => in_array($l['state'], ['needsUpdate', 'pending'], true)),
                'confirmed' => $nonDefault->isNotEmpty() && $nonDefault->every(fn (array $l) => $l['state'] === 'confirmed'),
                default => true, // 'all'
            };
        })->values();

        $total = $filtered->count();
        $lastPage = max(1, (int) ceil($total / self::QUEUE_PER_PAGE));
        $page = max(1, min($this->queuePage, $lastPage));

        $services = $filtered->slice(($page - 1) * self::QUEUE_PER_PAGE, self::QUEUE_PER_PAGE)->values();

        return ['services' => $services, 'page' => $page, 'lastPage' => $lastPage, 'total' => $total];
    }

    /**
     * One of: default (the source language), pending (queued/running), error (the last
     * translation attempt failed and nothing successful exists yet - see the "error" priority
     * note below), missing (no row, or never checked, or checked and found not translated),
     * needsUpdate (translated - by AI or independently - but not yet confirmed live on the real
     * site, see ServiceTranslation::needsSiteUpdate()), confirmed (translated and confirmed live).
     */
    private function descriptionLanguageState(Language $language, ?ServiceTranslation $row, array $pendingLangs, array $failedLangs = []): string
    {
        if ($language->is_default) {
            return 'default';
        }

        if (in_array($language->code, $pendingLangs, true)) {
            return 'pending';
        }

        $looksTranslated = $row && $row->looksTranslated();

        // Only surfaces as its own state while there's nothing successful to fall back to - a
        // failed *re*-translate attempt on a language that's already confirmed live (or waiting
        // to be uploaded) doesn't erase that real state, it's still visible per-language in the
        // details popup (serviceDetails()'s 'error' field) without demoting the badge here.
        if (! $looksTranslated && in_array($language->code, $failedLangs, true)) {
            return 'error';
        }

        if (! $looksTranslated) {
            return 'missing';
        }

        return $row->needsSiteUpdate() ? 'needsUpdate' : 'confirmed';
    }

    // The title counterpart to descriptionLanguageState() - identical shape, reading
    // titleLooksTranslated()/titleNeedsSiteUpdate() instead.
    private function titleLanguageState(Language $language, ?ServiceTranslation $row, array $pendingLangs, array $failedLangs = []): string
    {
        if ($language->is_default) {
            return 'default';
        }

        if (in_array($language->code, $pendingLangs, true)) {
            return 'pending';
        }

        $looksTranslated = $row && $row->titleLooksTranslated();

        if (! $looksTranslated && in_array($language->code, $failedLangs, true)) {
            return 'error';
        }

        if (! $looksTranslated) {
            return 'missing';
        }

        return $row->titleNeedsSiteUpdate() ? 'needsUpdate' : 'confirmed';
    }

    /**
     * Fetches the default-language catalog fresh (picking up new/changed services) and re-checks
     * every active language's live page - the manual, immediate counterpart to the scheduled
     * services:refresh-catalog command (every 12 hours).
     */
    public function runSyncNow(ServiceCatalogService $catalog): void
    {
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

        $checked = 0;
        $translated = 0;
        $errors = 0;

        foreach ($activeLanguages as $langCode) {
            $result = $catalog->refreshLanguage($langCode);

            if ($result['ok']) {
                $checked += $result['checked'];
                $translated += $result['translated'];
            } else {
                $errors++;
            }
        }

        $this->lastSyncResult = [
            'total' => $sync['total'],
            'new' => $sync['new'],
            'changed' => $sync['changed'],
            'checked' => $checked,
            'translated' => $translated,
            'errors' => $errors,
        ];

        unset($this->queue);

        Notification::make()
            ->title('Sync complete')
            ->body("Synced {$sync['total']} service(s) ({$sync['new']} new, {$sync['changed']} changed). Checked {$checked} across active languages, {$translated} confirmed translated.")
            ->success()
            ->send();
    }

    /**
     * The services counterpart to BlogTranslationQueue::downloadSeoExport() - same shape (a zip,
     * one text file per selected language, picked via checkboxes in the same collapsible panel
     * inside the details popup), just scoped to one service instead of one blog topic.
     */
    public function downloadServiceExport(string $serviceKey, array $langCodes): mixed
    {
        return $this->buildServiceZip(
            [$serviceKey => $langCodes],
            fn (ServiceTranslation $row) => $row->description,
            "service-export-{$serviceKey}.zip",
        );
    }

    // The title counterpart to downloadServiceExport() - separate download entirely, never
    // combined into the same file or zip as the description export.
    public function downloadServiceTitleExport(string $serviceKey, array $langCodes): mixed
    {
        return $this->buildServiceZip(
            [$serviceKey => $langCodes],
            fn (ServiceTranslation $row) => $row->title,
            "service-title-export-{$serviceKey}.zip",
        );
    }

    /**
     * @return array{ok: bool, message: string}
     */
    public function translateLanguage(string $serviceKey, string $targetLangCode): array
    {
        return $this->queueSingle($serviceKey, $targetLangCode, ServiceTranslationJob::FIELD_DESCRIPTION);
    }

    // The title counterpart to translateLanguage().
    public function translateTitle(string $serviceKey, string $targetLangCode): array
    {
        return $this->queueSingle($serviceKey, $targetLangCode, ServiceTranslationJob::FIELD_TITLE);
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function queueSingle(string $serviceKey, string $targetLangCode, string $field): array
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return ['ok' => false, 'message' => 'Database update needed first.'];
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return ['ok' => false, 'message' => 'Panel update in progress.'];
        }

        if ($this->hasPendingJob($serviceKey, $targetLangCode, $field)) {
            Notification::make()
                ->title('Already translating')
                ->body('This language is already queued or in progress for this service.')
                ->warning()
                ->send();

            return ['ok' => true, 'message' => 'Already queued.'];
        }

        $this->queueTranslation($serviceKey, $targetLangCode, $field);

        unset($this->queue);

        Notification::make()
            ->title('Translation queued')
            ->body('Translating in the background - reopen this page in a minute to see it land.')
            ->success()
            ->send();

        return ['ok' => true, 'message' => 'Translation queued.'];
    }

    /**
     * Queues every missing language for one service at once.
     */
    public function translateAllMissingForService(string $serviceKey): void
    {
        $this->queueAllMissingForService($serviceKey, ServiceTranslationJob::FIELD_DESCRIPTION);
    }

    // The title counterpart to translateAllMissingForService().
    public function translateAllMissingTitlesForService(string $serviceKey): void
    {
        $this->queueAllMissingForService($serviceKey, ServiceTranslationJob::FIELD_TITLE);
    }

    private function queueAllMissingForService(string $serviceKey, string $field): void
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        $missingLanguages = $this->missingLanguagesFor($serviceKey, $field);

        if ($missingLanguages->isEmpty()) {
            Notification::make()->title('Nothing left to translate')->success()->send();

            return;
        }

        foreach ($missingLanguages as $langCode) {
            $this->queueTranslation($serviceKey, $langCode, $field);
        }

        unset($this->queue);

        Notification::make()
            ->title("Queued {$missingLanguages->count()} language(s) for translation")
            ->success()
            ->send();
    }

    /**
     * Bulk counterpart - queues every missing language for every selected service.
     */
    public function queueMissingForSelected(): void
    {
        $this->queueMissingForSelectedField(ServiceTranslationJob::FIELD_DESCRIPTION);
    }

    // The title counterpart to queueMissingForSelected().
    public function queueMissingTitlesForSelected(): void
    {
        $this->queueMissingForSelectedField(ServiceTranslationJob::FIELD_TITLE);
    }

    private function queueMissingForSelectedField(string $field): void
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        if (empty($this->selectedServices)) {
            Notification::make()->title('No services selected')->warning()->send();

            return;
        }

        $totalQueued = 0;
        $servicesAffected = 0;

        foreach ($this->selectedServices as $serviceKey) {
            $missingLanguages = $this->missingLanguagesFor($serviceKey, $field);

            foreach ($missingLanguages as $langCode) {
                $this->queueTranslation($serviceKey, $langCode, $field);
            }

            if ($missingLanguages->isNotEmpty()) {
                $totalQueued += $missingLanguages->count();
                $servicesAffected++;
            }
        }

        $this->selectedServices = [];
        unset($this->queue);

        if ($totalQueued === 0) {
            Notification::make()
                ->title('Nothing to queue')
                ->body('None of the selected services have a missing language right now.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title("Queued {$totalQueued} translation(s) across {$servicesAffected} service(s)")
            ->success()
            ->send();
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function missingLanguagesFor(string $serviceKey, string $field): \Illuminate\Support\Collection
    {
        $existingByLang = ServiceTranslation::query()->where('service_key', $serviceKey)->get()->keyBy('lang');

        $pendingLangs = ServiceTranslationJob::query()
            ->where('service_key', $serviceKey)
            ->where('field', $field)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
            ->pluck('target_lang')
            ->all();

        $isTitle = $field === ServiceTranslationJob::FIELD_TITLE;

        return Language::query()
            ->where('is_active', true)
            ->where('is_default', false)
            ->pluck('code')
            ->filter(function (string $code) use ($existingByLang, $pendingLangs, $isTitle) {
                if (in_array($code, $pendingLangs, true)) {
                    return false;
                }

                $row = $existingByLang->get($code);

                return ! $row || ! ($isTitle ? $row->titleLooksTranslated() : $row->looksTranslated());
            })
            ->values();
    }

    public function viewServiceAction(): Action
    {
        return Action::make('viewService')
            ->label('Details')
            ->icon('heroicon-o-information-circle')
            ->color('gray')
            ->modalHeading(fn (array $arguments) => $arguments['title'] ?? 'Service details')
            ->modalWidth(MaxWidth::ThreeExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn (array $arguments) => view(
                'filament.pages.service-translation-details',
                $this->serviceDetails($arguments['serviceKey']),
            ));
    }

    /**
     * The bulk counterpart to the per-service export panel inside viewServiceAction()'s modal -
     * same "pick languages, then download" shape, just for every currently-selected service at
     * once. Reached from the bulk-selection bar's "Actions" dropdown.
     */
    public function downloadSelectionAction(): Action
    {
        return Action::make('downloadSelection')
            ->label('Download descriptions')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download descriptions for selected services')
            ->modalWidth(MaxWidth::Medium)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.pages.service-translation-bulk-export', [
                'languages' => $this->activeNonDefaultLanguages(),
                'selectedCount' => count($this->selectedServices),
                'wireMethod' => 'downloadSelectedServicesExport',
                'fileExample' => '5/fa.txt',
                'fieldLabel' => 'description',
            ]));
    }

    // The title counterpart to downloadSelectionAction() - separate modal/action entirely, never
    // combined with the description one.
    public function downloadSelectionTitlesAction(): Action
    {
        return Action::make('downloadSelectionTitles')
            ->label('Download titles')
            ->icon('heroicon-o-arrow-down-tray')
            ->modalHeading('Download titles for selected services')
            ->modalWidth(MaxWidth::Medium)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->modalContent(fn () => view('filament.pages.service-translation-bulk-export', [
                'languages' => $this->activeNonDefaultLanguages(),
                'selectedCount' => count($this->selectedServices),
                'wireMethod' => 'downloadSelectedServicesTitlesExport',
                'fileExample' => '5/fa.txt',
                'fieldLabel' => 'title',
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

    /**
     * Builds a zip for the selected services and chosen languages, one folder per service id
     * (e.g. "5/fa.txt") so translations for different services never collide once extracted -
     * each file holds just that language's description text, same as the per-service export.
     */
    public function downloadSelectedServicesExport(array $langCodes): mixed
    {
        if (empty($this->selectedServices)) {
            Notification::make()->title('No services selected')->warning()->send();

            return null;
        }

        $byService = collect($this->selectedServices)->mapWithKeys(fn ($key) => [$key => $langCodes])->all();

        return $this->buildServiceZip($byService, fn (ServiceTranslation $row) => $row->description, 'service-translations-selection.zip');
    }

    // The title counterpart to downloadSelectedServicesExport() - separate zip entirely.
    public function downloadSelectedServicesTitlesExport(array $langCodes): mixed
    {
        if (empty($this->selectedServices)) {
            Notification::make()->title('No services selected')->warning()->send();

            return null;
        }

        $byService = collect($this->selectedServices)->mapWithKeys(fn ($key) => [$key => $langCodes])->all();

        return $this->buildServiceZip($byService, fn (ServiceTranslation $row) => $row->title, 'service-titles-selection.zip');
    }

    /**
     * Shared zip-building logic behind all four download actions above - which text field to
     * read off each row (description or title) is the only thing that varies between them,
     * passed in as $valueResolver so the description and title paths can never accidentally
     * cross-contaminate one export with the other's content.
     *
     * @param  array<string, list<string>>  $langCodesByService
     */
    private function buildServiceZip(array $langCodesByService, \Closure $valueResolver, string $downloadName): mixed
    {
        $langCodesByService = collect($langCodesByService)
            ->map(fn ($codes) => array_values(array_unique(array_filter($codes))))
            ->filter(fn ($codes) => ! empty($codes));

        if ($langCodesByService->isEmpty()) {
            Notification::make()->title('Select at least one language first')->warning()->send();

            return null;
        }

        $allLangCodes = $langCodesByService->flatten()->unique()->values()->all();

        $rows = ServiceTranslation::query()
            ->whereIn('service_key', $langCodesByService->keys())
            ->whereIn('lang', $allLangCodes)
            ->get();

        $zipPath = tempnam(sys_get_temp_dir(), 'service-export-').'.zip';
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $added = 0;
        $singleService = $langCodesByService->count() === 1;

        foreach ($rows as $row) {
            $wantedLangs = $langCodesByService->get($row->service_key, []);

            if (! in_array($row->lang, $wantedLangs, true)) {
                continue;
            }

            $value = $valueResolver($row);

            if (blank($value)) {
                continue;
            }

            $filename = $singleService ? "{$row->lang}.txt" : "{$row->service_key}/{$row->lang}.txt";
            $zip->addFromString($filename, $value."\n");
            $added++;
        }

        if ($added === 0) {
            $zip->close();
            @unlink($zipPath);

            Notification::make()
                ->title('Nothing to export')
                ->body('None of the selected service(s) have content for the chosen languages.')
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
     * @return array{languages: \Illuminate\Support\Collection, defaultLangCode: string, serviceKey: string}
     */
    private function serviceDetails(string $serviceKey): array
    {
        $defaultLangCode = $this->defaultLangCode();

        $rows = ServiceTranslation::query()->where('service_key', $serviceKey)->get()->keyBy('lang');

        $languageDefs = Language::query()
            ->where('is_active', true)
            ->orderByRaw('is_default desc')
            ->orderBy('sort_order')
            ->get(['code', 'name', 'is_default']);

        $jobs = $this->translationTrackingAvailable()
            ? ServiceTranslationJob::query()->where('service_key', $serviceKey)->get()->groupBy('field')
            : collect();

        $descJobs = $jobs->get(ServiceTranslationJob::FIELD_DESCRIPTION, collect())->keyBy('target_lang');
        $titleJobs = $jobs->get(ServiceTranslationJob::FIELD_TITLE, collect())->keyBy('target_lang');

        $pendingLangs = $descJobs->filter(fn (ServiceTranslationJob $job) => in_array($job->status, ServiceTranslationJob::PENDING_STATUSES, true))->keys()->all();
        $pendingTitleLangs = $titleJobs->filter(fn (ServiceTranslationJob $job) => in_array($job->status, ServiceTranslationJob::PENDING_STATUSES, true))->keys()->all();

        $languages = $languageDefs->map(function (Language $language) use ($rows, $pendingLangs, $pendingTitleLangs, $descJobs, $titleJobs) {
            /** @var ?ServiceTranslation $row */
            $row = $rows->get($language->code);

            return [
                'code' => $language->code,
                'name' => $language->name,
                'isDefault' => $language->is_default,
                'exists' => (bool) $row,
                'isTranslated' => $row !== null && $row->looksTranslated(),
                'needsSiteUpdate' => $row !== null && $row->needsSiteUpdate(),
                'title' => $row?->title,
                'description' => $row?->description,
                'checkedAt' => $row?->checked_at,
                'checkNote' => $row?->check_note,
                'pending' => in_array($language->code, $pendingLangs, true),
                'error' => $descJobs->get($language->code)?->status === ServiceTranslationJob::FAILED
                    ? $descJobs->get($language->code)->message
                    : null,
                'titleIsTranslated' => $row !== null && $row->titleLooksTranslated(),
                'titleNeedsSiteUpdate' => $row !== null && $row->titleNeedsSiteUpdate(),
                'titleCheckedAt' => $row?->checked_at,
                'titleCheckNote' => $row?->title_check_note,
                'titlePending' => in_array($language->code, $pendingTitleLangs, true),
                'titleError' => $titleJobs->get($language->code)?->status === ServiceTranslationJob::FAILED
                    ? $titleJobs->get($language->code)->message
                    : null,
                'retranslated' => $row !== null && $row->wasRecentlyAutoRetranslatedDescription(),
                'titleRetranslated' => $row !== null && $row->wasRecentlyAutoRetranslatedTitle(),
            ];
        })->values();

        return [
            'languages' => $languages,
            'defaultLangCode' => $defaultLangCode,
            'serviceKey' => $serviceKey,
            'categoryTitle' => $rows->get($defaultLangCode)?->category_title,
        ];
    }

    private function defaultLangCode(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    private function queueTranslation(string $serviceKey, string $targetLangCode, string $field): void
    {
        ServiceTranslationJob::query()->updateOrCreate(
            ['service_key' => $serviceKey, 'target_lang' => $targetLangCode, 'field' => $field],
            ['status' => ServiceTranslationJob::QUEUED, 'message' => null],
        );
    }

    private function hasPendingJob(string $serviceKey, string $targetLangCode, string $field): bool
    {
        return ServiceTranslationJob::query()
            ->where('service_key', $serviceKey)
            ->where('target_lang', $targetLangCode)
            ->where('field', $field)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
            ->exists();
    }

    // Same reasoning as BlogTranslationQueue's own translationTrackingAvailable() guard - an
    // admin without shell access has no way to run these tables' migrations except the "Update
    // database" button (General Settings). Requires both tables (not just the jobs one this was
    // originally named for) so every existing call site - including queue() itself - is
    // protected against the catalog table not existing yet either, rather than needing a second,
    // separately-remembered guard sprinkled across every method that touches ServiceTranslation.
    private static ?bool $translationTrackingAvailable = null;

    private function translationTrackingAvailable(): bool
    {
        return self::$translationTrackingAvailable ??= Schema::hasTable('service_translations')
            && Schema::hasTable('service_translation_jobs')
            && Schema::hasColumn('service_translation_jobs', 'field');
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
