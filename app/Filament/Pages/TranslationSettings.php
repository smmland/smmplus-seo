<?php

namespace App\Filament\Pages;

use App\Services\AiSettingsService;
use App\Services\BlogTranslationDetectionService;
use App\Services\TranslationSettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
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

    public ?array $aiTestResult = null;

    public function mount(TranslationSettingsService $settings, AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            'autoHideEnabled' => $settings->isAutoHideEnabled(),
            'recheckIntervalHours' => $settings->getRecheckIntervalHours(),
            'aiProvider' => $aiSettings->getProvider(),
            // Never pre-filled with the real secret - blank means "keep the saved one" on save.
            'aiApiKey' => null,
            'blogTranslationPrompt' => $aiSettings->getBlogTranslationPrompt(),
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

                Section::make('AI translation connection')
                    ->description('Used to auto-translate blog content that\'s missing a language. Pick one provider and enter its API key - "Test connection" checks it against the provider directly, without saving first.')
                    ->schema([
                        Select::make('aiProvider')
                            ->label('Provider')
                            ->options(AiSettingsService::PROVIDER_LABELS)
                            ->required()
                            ->live(),

                        TextInput::make('aiApiKey')
                            ->label('API key')
                            ->password()
                            ->revealable()
                            ->helperText(fn (Get $get) => app(AiSettingsService::class)->hasApiKey($get('aiProvider'))
                                ? 'A key is already saved for this provider - leave blank to keep it, or type a new one to replace it.'
                                : 'No key saved yet for this provider.'),
                    ])
                    ->columns(2),

                Section::make('Blog translation prompt')
                    ->description('Sent to the AI when translating blog content. {{tokens}} are replaced with the real title/content/meta before sending - see the list below the field.')
                    ->schema([
                        Textarea::make('blogTranslationPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(16)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(TranslationSettingsService $settings, AiSettingsService $aiSettings): void
    {
        $data = $this->form->getState();

        $settings->setSettings(
            (bool) $data['autoHideEnabled'],
            (int) $data['recheckIntervalHours'],
        );

        $aiSettings->setProvider($data['aiProvider']);
        $aiSettings->setApiKey($data['aiProvider'], $data['aiApiKey'] ?: null);
        $aiSettings->setBlogTranslationPrompt($data['blogTranslationPrompt']);

        // The typed key was only ever meant to reach storage (encrypted) - don't leave it
        // sitting in the live form state after a successful save.
        $this->form->fill([...$this->form->getState(), 'aiApiKey' => null]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }

    public function testAiConnection(AiSettingsService $aiSettings): void
    {
        $data = $this->form->getState();
        $provider = $data['aiProvider'];
        $apiKey = filled($data['aiApiKey']) ? $data['aiApiKey'] : ($aiSettings->getApiKey($provider) ?? '');

        $this->aiTestResult = $aiSettings->testConnection($provider, $apiKey);

        $notification = Notification::make()
            ->title($this->aiTestResult['ok'] ? 'Connection successful' : 'Connection failed')
            ->body($this->aiTestResult['message']);

        $this->aiTestResult['ok'] ? $notification->success() : $notification->danger();

        $notification->send();
    }

    public function resetPromptToDefault(AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'blogTranslationPrompt' => $aiSettings->defaultBlogTranslationPrompt(),
        ]);

        Notification::make()
            ->title('Prompt reset to default')
            ->body('Not saved yet - click Save to keep it.')
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
