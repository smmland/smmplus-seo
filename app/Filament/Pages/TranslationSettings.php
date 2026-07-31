<?php

namespace App\Filament\Pages;

use App\Services\AdminAutomationSettingsService;
use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

class TranslationSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Settings';

    // Pushed to the end of the group - everything else in the group defaults to sort -1.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.translation-settings';

    public ?array $data = [];

    public ?array $lastRunResult = null;

    public function mount(TranslationSettingsService $settings, AdminAutomationSettingsService $automationSettings): void
    {
        $this->form->fill([
            'autoHideEnabled' => $settings->isAutoHideEnabled(),
            'recheckIntervalHours' => $settings->getRecheckIntervalHours(),

            'adminPanelUrl' => $automationSettings->getPanelUrl(),
            'adminUsername' => $automationSettings->getUsername(),
            'adminPassword' => null,
            'automationServiceUrl' => $automationSettings->getServiceUrl(),
            'automationServiceToken' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Toggle::make('autoHideEnabled')
                    ->label('Automatically hide untranslated blog pages')
                    ->helperText('When a non-default-language blog page\'s title still matches the default language, hide it from the sitemap until it\'s translated.'),

                TextInput::make('recheckIntervalHours')
                    ->label('Recheck cycle (hours)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('How often each blog URL gets re-checked. Runs hourly in the background but only actually re-checks a URL once this many hours have passed since its last check.'),

                Section::make('Admin panel automation')
                    ->description('Credentials and endpoint used by the "Admin Login" page to sign in to the panel\'s admin area and open the Blog page there, so translated posts can eventually be published back to it.')
                    ->schema([
                        TextInput::make('adminPanelUrl')
                            ->label('Panel URL')
                            ->url()
                            ->required()
                            ->helperText('Currently pointed at the test panel (smmto.com); switch to the real smmplus panel URL once this is verified.'),

                        TextInput::make('adminUsername')
                            ->label('Admin username'),

                        TextInput::make('adminPassword')
                            ->label('Admin password')
                            ->password()
                            ->revealable()
                            ->helperText(fn (AdminAutomationSettingsService $automationSettings) => $automationSettings->hasPassword()
                                ? 'A password is already saved. Leave blank to keep it.'
                                : 'No password saved yet.'),

                        TextInput::make('automationServiceUrl')
                            ->label('Automation service URL')
                            ->url()
                            ->helperText('Base URL of the Node/Playwright login service (see automation/README.md), e.g. https://automation.example.com'),

                        TextInput::make('automationServiceToken')
                            ->label('Automation service token')
                            ->password()
                            ->revealable()
                            ->helperText(fn (AdminAutomationSettingsService $automationSettings) => $automationSettings->hasServiceToken()
                                ? 'A token is already saved. Leave blank to keep it.'
                                : 'Must match AUTOMATION_TOKEN on the automation service.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(TranslationSettingsService $settings, AdminAutomationSettingsService $automationSettings): void
    {
        $data = $this->form->getState();

        $settings->setSettings(
            (bool) $data['autoHideEnabled'],
            (int) $data['recheckIntervalHours'],
        );

        $automationSettings->setSettings(
            $data['adminPanelUrl'],
            $data['adminUsername'] ?: null,
            $data['adminPassword'] ?: null,
            $data['automationServiceUrl'] ?: null,
            $data['automationServiceToken'] ?: null,
        );

        $this->form->fill([
            ...$data,
            'adminPassword' => null,
            'automationServiceToken' => null,
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function runNow(BlogTranslationDetectionService $detector): void
    {
        // Bounded well below what would risk a PHP execution timeout on this host - a large
        // backlog is worked off a batch at a time by clicking again, or by the hourly schedule.
        // The pending count below shows whether everything's actually been covered yet.
        $this->lastRunResult = $detector->refresh(force: true, limit: 40);

        unset($this->pendingCount);

        Notification::make()
            ->title('Translation check complete')
            ->body("Checked {$this->lastRunResult['checked']}, hid {$this->lastRunResult['hidden']}, unhid {$this->lastRunResult['unhidden']}, errors {$this->lastRunResult['errors']}.")
            ->success()
            ->send();
    }

    #[Computed]
    public function pendingCount(): int
    {
        return app(BlogTranslationDetectionService::class)->pendingCount();
    }
}
