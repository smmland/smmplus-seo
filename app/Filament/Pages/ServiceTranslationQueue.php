<?php

namespace App\Filament\Pages;

use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use App\Services\ServiceCatalogService;
use App\Services\SettingsService;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Enums\MaxWidth;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;

/**
 * The services counterpart to BlogTranslationQueue - structurally simpler, since every service
 * (across every language) lives on one shared listing page rather than one URL per item, so
 * there's no per-row "extract content" step and no separate "confirmed live on the real site" vs
 * "translated by us" distinction: ServiceCatalogService::refreshLanguage() IS the live check,
 * comparing each language's actual page directly.
 */
class ServiceTranslationQueue extends Page implements HasActions
{
    use InteractsWithActions;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Service Translation';

    protected static string $view = 'filament.pages.service-translation-queue';

    public string $search = '';

    public string $statusFilter = 'missing';

    public int $queuePage = 1;

    // service_key values, not row ids - a service spans several service_translations rows (one
    // per language) and the bulk action below operates per-service, not per-row.
    public array $selectedServices = [];

    public ?array $lastSyncResult = null;

    private const QUEUE_PER_PAGE = 20;

    public const STATUS_FILTERS = [
        'missing' => 'Has a missing language',
        'translated' => 'Fully translated',
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

    /**
     * @return array{services: \Illuminate\Support\Collection, page: int, lastPage: int, total: int}
     */
    #[Computed]
    public function queue()
    {
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

        $pendingByKey = $this->translationTrackingAvailable()
            ? ServiceTranslationJob::query()
                ->whereIn('service_key', $defaultRows->pluck('service_key'))
                ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
                ->get()
                ->groupBy('service_key')
            : collect();

        $allServices = $defaultRows->map(function (ServiceTranslation $defaultRow) use ($existingByKey, $activeLanguages, $pendingByKey) {
            $existingForKey = $existingByKey->get($defaultRow->service_key, collect())->keyBy('lang');
            $pendingLangs = $pendingByKey->get($defaultRow->service_key, collect())->pluck('target_lang')->all();

            $languages = $activeLanguages->map(function (Language $language) use ($existingForKey, $pendingLangs) {
                $row = $existingForKey->get($language->code);

                return [
                    'code' => $language->code,
                    'name' => $language->name,
                    'state' => $this->languageState($language, $row, $pendingLangs),
                    'description' => $row?->description,
                ];
            });

            return [
                'row' => $defaultRow,
                'languages' => $languages,
                'pendingLangs' => $pendingLangs,
            ];
        });

        $filtered = $allServices->filter(function (array $service) {
            $nonDefault = $service['languages']->where('state', '!=', 'default');

            return match ($this->statusFilter) {
                'missing' => $nonDefault->contains(fn (array $l) => in_array($l['state'], ['missing', 'pending'], true)),
                'translated' => $nonDefault->isNotEmpty() && $nonDefault->every(fn (array $l) => $l['state'] === 'translated'),
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
     * One of: default (the source language), pending (queued/running), missing (no row, or never
     * checked, or checked and found not translated), translated (checked live and confirmed to
     * genuinely differ from the default language). No separate "needs site update" state here -
     * unlike a blog URL, refreshLanguage() reads the real live page directly every time, so
     * "translated" already means confirmed live.
     */
    private function languageState(Language $language, ?ServiceTranslation $row, array $pendingLangs): string
    {
        if ($language->is_default) {
            return 'default';
        }

        if (in_array($language->code, $pendingLangs, true)) {
            return 'pending';
        }

        return ($row && $row->looksTranslated()) ? 'translated' : 'missing';
    }

    /**
     * Fetches the default-language catalog fresh (picking up new/changed services) and re-checks
     * every active language's live page - the manual, immediate counterpart to the scheduled
     * services:refresh-catalog command (every 12 hours).
     */
    public function runSyncNow(ServiceCatalogService $catalog): void
    {
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
     * @return array{ok: bool, message: string}
     */
    public function translateLanguage(string $serviceKey, string $targetLangCode): array
    {
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return ['ok' => false, 'message' => 'Database update needed first.'];
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return ['ok' => false, 'message' => 'Panel update in progress.'];
        }

        if ($this->hasPendingTranslation($serviceKey, $targetLangCode)) {
            Notification::make()
                ->title('Already translating')
                ->body('This language is already queued or in progress for this service.')
                ->warning()
                ->send();

            return ['ok' => true, 'message' => 'Already queued.'];
        }

        $this->queueTranslation($serviceKey, $targetLangCode);

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
        if (! $this->translationTrackingAvailable()) {
            $this->notifyDatabaseUpdateNeeded();

            return;
        }

        if ($this->notifyIfPanelUpdateInProgress()) {
            return;
        }

        $missingLanguages = $this->missingLanguagesFor($serviceKey);

        if ($missingLanguages->isEmpty()) {
            Notification::make()->title('Nothing left to translate')->success()->send();

            return;
        }

        foreach ($missingLanguages as $langCode) {
            $this->queueTranslation($serviceKey, $langCode);
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
            $missingLanguages = $this->missingLanguagesFor($serviceKey);

            foreach ($missingLanguages as $langCode) {
                $this->queueTranslation($serviceKey, $langCode);
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
    private function missingLanguagesFor(string $serviceKey): \Illuminate\Support\Collection
    {
        $existingByLang = ServiceTranslation::query()->where('service_key', $serviceKey)->get()->keyBy('lang');

        $pendingLangs = ServiceTranslationJob::query()
            ->where('service_key', $serviceKey)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
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
            ? ServiceTranslationJob::query()->where('service_key', $serviceKey)->get()->keyBy('target_lang')
            : collect();

        $pendingLangs = $jobs
            ->filter(fn (ServiceTranslationJob $job) => in_array($job->status, ServiceTranslationJob::PENDING_STATUSES, true))
            ->keys()
            ->all();

        $languages = $languageDefs->map(function (Language $language) use ($rows, $pendingLangs, $jobs) {
            /** @var ?ServiceTranslation $row */
            $row = $rows->get($language->code);

            return [
                'code' => $language->code,
                'name' => $language->name,
                'isDefault' => $language->is_default,
                'exists' => (bool) $row,
                'isTranslated' => $row !== null && $row->looksTranslated(),
                'title' => $row?->title,
                'description' => $row?->description,
                'checkedAt' => $row?->checked_at,
                'checkNote' => $row?->check_note,
                'pending' => in_array($language->code, $pendingLangs, true),
                'error' => $jobs->get($language->code)?->status === ServiceTranslationJob::FAILED
                    ? $jobs->get($language->code)->message
                    : null,
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

    private function queueTranslation(string $serviceKey, string $targetLangCode): void
    {
        ServiceTranslationJob::query()->updateOrCreate(
            ['service_key' => $serviceKey, 'target_lang' => $targetLangCode],
            ['status' => ServiceTranslationJob::QUEUED, 'message' => null],
        );
    }

    private function hasPendingTranslation(string $serviceKey, string $targetLangCode): bool
    {
        return ServiceTranslationJob::query()
            ->where('service_key', $serviceKey)
            ->where('target_lang', $targetLangCode)
            ->whereIn('status', ServiceTranslationJob::PENDING_STATUSES)
            ->exists();
    }

    // Same reasoning as BlogTranslationQueue's own translationTrackingAvailable() guard - an
    // admin without shell access has no way to run this table's migration except the "Update
    // database" button (General Settings).
    private static ?bool $translationTrackingAvailable = null;

    private function translationTrackingAvailable(): bool
    {
        return self::$translationTrackingAvailable ??= Schema::hasTable('service_translation_jobs');
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
