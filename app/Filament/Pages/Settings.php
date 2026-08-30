<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Settings\NewUrlsOverTimeChart;
use App\Filament\Resources\SyncRunResource;
use App\Models\SyncRun;
use App\Services\CatalogSettingsService;
use App\Services\SettingsService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'SEO';

    // Pushed to the end of the SEO group - everything else in the group defaults to sort -1.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::SEO, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public ?array $catalogData = [];

    public ?SyncRun $latestRun = null;

    public function mount(SettingsService $settings, CatalogSettingsService $catalogSettings): void
    {
        $this->form->fill([
            'syncIntervalHours' => $settings->getSyncIntervalHours(),
            'sourceSitemapUrl' => $settings->getSourceSitemapUrl(),
        ]);

        $this->catalogForm->fill([
            'catalogApiKey' => null,
            'catalogApiHost' => $catalogSettings->getHost($settings),
            'catalogCurrencySymbol' => $catalogSettings->getCurrencySymbol(),
        ]);

        $this->latestRun = SyncRun::query()->latest('started_at')->first();
    }

    public function getForms(): array
    {
        return ['form', 'catalogForm'];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            NewUrlsOverTimeChart::class,
        ];
    }

    public function getSyncHistoryUrl(): string
    {
        return SyncRunResource::getUrl();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('syncIntervalHours')
                    ->label('Sync interval (hours)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('How often the scheduled sync (cron) re-fetches the source sitemap.'),

                TextInput::make('sourceSitemapUrl')
                    ->label('Source sitemap URL')
                    ->url()
                    ->required()
                    ->helperText('The main site\'s own sitemap.xml that gets mirrored and categorized.'),
            ])
            ->statePath('data');
    }

    public function save(SettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setSyncIntervalHours((int) $data['syncIntervalHours']);
        $settings->setSourceSitemapUrl($data['sourceSitemapUrl']);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    // Credentials for smm.plus's own customer API (https://smm.plus/api) - what CatalogSyncService
    // uses to keep GET /api/services's cached price/min/max fresh. Independent form/save from the
    // sitemap settings above, same reasoning as GeneralSettings' aiForm: a wrong or blank sync
    // interval shouldn't block saving an API key, or vice versa.
    public function catalogForm(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('catalogApiKey')
                    ->label('smm.plus API key')
                    ->password()
                    ->revealable()
                    ->helperText(fn (CatalogSettingsService $catalogSettings) => $catalogSettings->hasApiKey()
                        ? 'A key is already saved - leave blank to keep it, or type a new one to replace it.'
                        : 'No key saved yet - the customer API key from your own smm.plus account (https://smm.plus/api).'),

                TextInput::make('catalogApiHost')
                    ->label('API host')
                    ->helperText('Defaults to the same domain as the source sitemap URL above. Only override if smm.plus\'s customer API lives elsewhere.'),

                TextInput::make('catalogCurrencySymbol')
                    ->label('Currency symbol')
                    ->required()
                    ->maxLength(5)
                    ->helperText('Display-only, used to format rate_formatted on GET /api/services - smm.plus\'s services API does not report a currency itself.'),
            ])
            ->statePath('catalogData');
    }

    public function saveCatalog(CatalogSettingsService $catalogSettings): void
    {
        $data = $this->catalogForm->getState();

        $catalogSettings->setApiKey($data['catalogApiKey'] ?: null);
        $catalogSettings->setHost($data['catalogApiHost'] ?: null);
        $catalogSettings->setCurrencySymbol($data['catalogCurrencySymbol']);

        // Never keep the typed secret in live form state after saving - same reasoning as
        // GeneralSettings' aiApiKey field.
        $this->catalogForm->fill([...$this->catalogForm->getState(), 'catalogApiKey' => null]);

        Notification::make()
            ->title('Catalog API settings saved')
            ->success()
            ->send();
    }
}
