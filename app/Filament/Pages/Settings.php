<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Settings\NewUrlsOverTimeChart;
use App\Filament\Resources\SyncRunResource;
use App\Models\SyncRun;
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

    public ?SyncRun $latestRun = null;

    public function mount(SettingsService $settings): void
    {
        $this->form->fill([
            'syncIntervalHours' => $settings->getSyncIntervalHours(),
            'sourceSitemapUrl' => $settings->getSourceSitemapUrl(),
        ]);

        $this->latestRun = SyncRun::query()->latest('started_at')->first();
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
}
