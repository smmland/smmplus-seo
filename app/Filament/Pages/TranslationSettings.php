<?php

namespace App\Filament\Pages;

use App\Services\AiSettingsService;
use App\Services\BlogTranslationDetectionService;
use App\Services\HiddenTranslationService;
use App\Services\TranslationSettingsService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::TRANSLATION, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public ?array $lastRunResult = null;

    public function mount(TranslationSettingsService $settings, AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            'autoHideEnabled' => $settings->isAutoHideEnabled(),
            'recheckIntervalHours' => $settings->getRecheckIntervalHours(),
            'autoExtractEnabled' => $settings->isAutoExtractNewBlogsEnabled(),
            'autoTranslateEnabled' => $settings->isAutoTranslateNewBlogsEnabled(),
            'blogTranslationPrompt' => $aiSettings->getBlogTranslationPrompt(),
            'serviceTranslationPrompt' => $aiSettings->getServiceTranslationPrompt(),
            'serviceTitleTranslationPrompt' => $aiSettings->getServiceTitleTranslationPrompt(),
            'categoryTranslationPrompt' => $aiSettings->getCategoryTranslationPrompt(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('recheckIntervalHours')
                    ->label('Recheck cycle (hours)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('How often each blog URL gets re-checked. Runs hourly in the background but only actually re-checks a URL once this many hours have passed since its last check.'),

                Section::make('New blog topics')
                    ->description('When the sitemap sync discovers a new blog topic in the default language, automatically prepare it instead of waiting for a manual click.')
                    ->schema([
                        Toggle::make('autoExtractEnabled')
                            ->label('Automatically extract content for new blog topics')
                            ->helperText('Runs the same content extraction as the "Extract content" button on the Blog Translation queue.')
                            ->live()
                            ->afterStateUpdated(function (bool $state, Set $set) {
                                if (! $state) {
                                    $set('autoTranslateEnabled', false);
                                }
                            }),

                        Toggle::make('autoTranslateEnabled')
                            ->label('Automatically queue AI translation for new blog topics')
                            ->helperText('Requires auto-extract above - a topic is only queued for translation once its content has actually been extracted.')
                            ->disabled(fn (Get $get) => ! $get('autoExtractEnabled')),
                    ]),

                Section::make('Advanced tools')
                    ->description('Rarely needed - collapsed by default.')
                    ->collapsible()
                    ->collapsed()
                    ->schema([
                        Toggle::make('autoHideEnabled')
                            ->label('Automatically hide untranslated blog pages')
                            ->helperText('When a non-default-language blog page\'s title still matches the default language, hide it from the sitemap until it\'s translated.'),
                    ]),

                Section::make('Blog translation prompt')
                    ->description('Sent to the AI when translating blog content. {{tokens}} are replaced with the real title/content/meta before sending - see the list below the field. The AI provider and API key are configured on General Settings.')
                    ->schema([
                        Textarea::make('blogTranslationPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(16)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),

                Section::make('Service translation prompt')
                    ->description('Sent to the AI when translating a service\'s description on the Service Translation page. {{tokens}} are replaced with the real service name/category/description before sending - see the list below the field. Uses the same AI provider and API key as blog translation.')
                    ->schema([
                        Textarea::make('serviceTranslationPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(12)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),

                Section::make('Service title translation prompt')
                    ->description('Sent to the AI when translating a service\'s title on the Service Translation page - kept fully separate from the description prompt above, since a title is translated, checked, and downloaded independently of its description.')
                    ->schema([
                        Textarea::make('serviceTitleTranslationPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(10)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),

                Section::make('Category translation prompt')
                    ->description('Sent to the AI when translating a category name on the Category Translation page - a category name is shared by every service in it, so it\'s translated and tracked on its own, separate from any one service\'s title.')
                    ->schema([
                        Textarea::make('categoryTranslationPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(10)
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

        $settings->setAutoProcessSettings(
            (bool) $data['autoExtractEnabled'],
            (bool) $data['autoTranslateEnabled'],
        );

        $aiSettings->setBlogTranslationPrompt($data['blogTranslationPrompt']);
        $aiSettings->setServiceTranslationPrompt($data['serviceTranslationPrompt']);
        $aiSettings->setServiceTitleTranslationPrompt($data['serviceTitleTranslationPrompt']);
        $aiSettings->setCategoryTranslationPrompt($data['categoryTranslationPrompt']);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
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

    public function resetServicePromptToDefault(AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'serviceTranslationPrompt' => $aiSettings->defaultServiceTranslationPrompt(),
        ]);

        Notification::make()
            ->title('Prompt reset to default')
            ->body('Not saved yet - click Save to keep it.')
            ->success()
            ->send();
    }

    public function resetServiceTitlePromptToDefault(AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'serviceTitleTranslationPrompt' => $aiSettings->defaultServiceTitleTranslationPrompt(),
        ]);

        Notification::make()
            ->title('Prompt reset to default')
            ->body('Not saved yet - click Save to keep it.')
            ->success()
            ->send();
    }

    public function resetCategoryPromptToDefault(AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'categoryTranslationPrompt' => $aiSettings->defaultCategoryTranslationPrompt(),
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

    // Moved out of the sidebar (HiddenTranslations::shouldRegisterNavigation() is false) - this
    // count is what makes the link below worth clicking instead of just a static "recovery tools"
    // link nobody ever opens.
    #[Computed]
    public function hiddenTranslationsCount(): int
    {
        $hidden = app(HiddenTranslationService::class);

        return $hidden->count() + $hidden->orphanedCount();
    }
}
