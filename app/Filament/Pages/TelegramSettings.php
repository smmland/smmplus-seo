<?php

namespace App\Filament\Pages;

use App\Services\AiSettingsService;
use App\Services\TelegramBotService;
use App\Services\TelegramSettingsService;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class TelegramSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Telegram Channel';

    protected static ?string $navigationLabel = 'Settings';

    // Pushed to the end of the group, same convention as TranslationSettings.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.telegram-settings';

    public ?array $data = [];

    public ?array $testResult = null;

    public function mount(TelegramSettingsService $settings, AiSettingsService $aiSettings): void
    {
        $this->form->fill([
            'enabled' => $settings->isEnabled(),
            'botToken' => null,
            'channelId' => $settings->getChannelId(),
            'imageGenerationEnabled' => $settings->isImageGenerationEnabled(),
            'imageModel' => $aiSettings->getImageModel(),
            'postsPerDay' => $settings->getPostsPerDay(),
            'blogSummaryPrompt' => $settings->getBlogSummaryPrompt(),
            'serviceAnnouncementPrompt' => $settings->getServiceAnnouncementPrompt(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Connection')
                    ->description('Create a bot via @BotFather on Telegram, then add it as an admin (with post permission) on the target channel. The channel id is either its @username or the numeric -100... chat id.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable Telegram posting')
                            ->helperText('While off, nothing is ever generated or sent, regardless of the schedule.'),

                        TextInput::make('botToken')
                            ->label('Bot token')
                            ->password()
                            ->revealable()
                            ->helperText(fn () => app(TelegramSettingsService::class)->hasBotToken()
                                ? 'A token is already saved - leave blank to keep it, or type a new one to replace it.'
                                : 'No token saved yet.'),

                        TextInput::make('channelId')
                            ->label('Channel id')
                            ->placeholder('@yourchannel or -1001234567890'),
                    ])
                    ->columns(2),

                Section::make('Content')
                    ->schema([
                        TextInput::make('postsPerDay')
                            ->label('Blog posts per day')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(20)
                            ->required()
                            ->helperText('How many blog-summary posts to keep scheduled per day, spread across the next 7 days.'),

                        Toggle::make('imageGenerationEnabled')
                            ->label('Generate images with AI')
                            ->helperText('Used for service-update announcements (which have no article photo of their own) and for any blog article that has no usable image. Costs money per image via OpenAI\'s Images API - off means those posts send as text-only instead.'),

                        TextInput::make('imageModel')
                            ->label('OpenAI image model')
                            ->helperText('Always OpenAI, regardless of which provider is active for text on AI Settings/General Settings - Claude has no image API. Uses the OpenAI API key saved there.')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Blog summary prompt')
                    ->description('Sent to the AI to write each blog-summary post. {{tokens}} are replaced with the real article title/content/URL before sending - see the list below the field.')
                    ->schema([
                        Textarea::make('blogSummaryPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(14)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),

                Section::make('Service change announcement prompt')
                    ->description('Sent to the AI to write each new/updated/removed service announcement. {{tokens}} are replaced with the real service name/category/change type before sending - see the list below the field.')
                    ->schema([
                        Textarea::make('serviceAnnouncementPrompt')
                            ->label('Prompt')
                            ->required()
                            ->rows(10)
                            ->extraInputAttributes(['class' => 'font-mono text-xs']),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(TelegramSettingsService $settings, AiSettingsService $aiSettings): void
    {
        $data = $this->form->getState();

        $settings->setEnabled((bool) $data['enabled']);
        $settings->setBotToken($data['botToken'] ?: null);
        $settings->setChannelId($data['channelId'] ?: null);
        $settings->setImageGenerationEnabled((bool) $data['imageGenerationEnabled']);
        $aiSettings->setImageModel($data['imageModel'] ?: null);
        $settings->setPostsPerDay((int) $data['postsPerDay']);
        $settings->setBlogSummaryPrompt($data['blogSummaryPrompt']);
        $settings->setServiceAnnouncementPrompt($data['serviceAnnouncementPrompt']);

        // The typed token was only ever meant to reach storage (encrypted) - don't leave it
        // sitting in the live form state after a successful save.
        $this->form->fill([...$this->form->getState(), 'botToken' => null]);

        Notification::make()
            ->title('Telegram settings saved')
            ->success()
            ->send();
    }

    public function testConnection(TelegramBotService $bot): void
    {
        $data = $this->form->getState();
        $tokenOverride = filled($data['botToken']) ? $data['botToken'] : null;

        $this->testResult = $bot->testConnection($tokenOverride);

        $notification = Notification::make()
            ->title($this->testResult['ok'] ? 'Connection successful' : 'Connection failed')
            ->body($this->testResult['message']);

        $this->testResult['ok'] ? $notification->success() : $notification->danger();

        $notification->send();
    }

    public function resetBlogSummaryPromptToDefault(TelegramSettingsService $settings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'blogSummaryPrompt' => $settings->defaultBlogSummaryPrompt(),
        ]);

        Notification::make()
            ->title('Prompt reset to default')
            ->body('Not saved yet - click Save to keep it.')
            ->success()
            ->send();
    }

    public function resetServiceAnnouncementPromptToDefault(TelegramSettingsService $settings): void
    {
        $this->form->fill([
            ...$this->form->getState(),
            'serviceAnnouncementPrompt' => $settings->defaultServiceAnnouncementPrompt(),
        ]);

        Notification::make()
            ->title('Prompt reset to default')
            ->body('Not saved yet - click Save to keep it.')
            ->success()
            ->send();
    }
}
