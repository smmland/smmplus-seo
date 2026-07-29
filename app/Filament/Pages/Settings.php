<?php

namespace App\Filament\Pages;

use App\Filament\Clusters\Seo;
use App\Services\SettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class Settings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $cluster = Seo::class;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static string $view = 'filament.pages.settings';

    public ?array $data = [];

    public function mount(SettingsService $settings): void
    {
        $this->form->fill([
            'syncIntervalHours' => $settings->getSyncIntervalHours(),
            'sourceSitemapUrl' => $settings->getSourceSitemapUrl(),
        ]);
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
