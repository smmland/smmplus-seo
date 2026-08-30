<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Settings\NewUrlsOverTimeChart;
use App\Filament\Resources\GatewayUpstreamResource;
use App\Filament\Resources\SyncRunResource;
use App\Models\GatewayUpstream;
use App\Models\SyncRun;
use App\Services\CatalogSettingsService;
use App\Services\SettingsService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Select;
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
            'catalogUpstreamId' => $catalogSettings->getUpstreamId(),
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

    // Which existing "API Server" (Free Service Gateway > API Servers) CatalogSyncService calls
    // to keep GET /api/services's cached price/min/max fresh - reuses that list instead of a
    // second copy of the same key/URL, same picker Telegram Settings' auto-views upstream field
    // already uses. Independent form/save from the sitemap settings above, same reasoning as
    // GeneralSettings' aiForm: a wrong or blank sync interval shouldn't block picking an API
    // server, or vice versa.
    public function catalogForm(Form $form): Form
    {
        return $form
            ->schema([
                Select::make('catalogUpstreamId')
                    ->label('API server')
                    ->options(fn () => GatewayUpstream::query()->where('is_active', true)->pluck('name', 'id'))
                    ->placeholder('Select an API server')
                    ->helperText(fn () => new \Illuminate\Support\HtmlString('The server whose "services" action returns the real price/min/max. Managed on <a href="'.GatewayUpstreamResource::getUrl().'" class="underline">Free Service Gateway &gt; API Servers</a> - e.g. an "SMM Plus Main" row pointing at https://smm.plus/api/v2.')),

                TextInput::make('catalogCurrencySymbol')
                    ->label('Currency symbol')
                    ->required()
                    ->maxLength(5)
                    ->helperText('Display-only, used to format rate_formatted on GET /api/services - the services action does not report a currency itself.'),
            ])
            ->statePath('catalogData');
    }

    public function saveCatalog(CatalogSettingsService $catalogSettings): void
    {
        $data = $this->catalogForm->getState();

        $catalogSettings->setUpstreamId($data['catalogUpstreamId'] !== null ? (int) $data['catalogUpstreamId'] : null);
        $catalogSettings->setCurrencySymbol($data['catalogCurrencySymbol']);

        Notification::make()
            ->title('Catalog API settings saved')
            ->success()
            ->send();
    }
}
