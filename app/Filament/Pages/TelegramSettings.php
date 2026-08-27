<?php

namespace App\Filament\Pages;

use App\Models\GatewayUpstream;
use App\Services\AiSettingsService;
use App\Services\TelegramAutoViewsSettingsService;
use App\Services\TelegramBotService;
use App\Services\TelegramSettingsService;
use App\Support\PanelSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Forms\Set;
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

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::TELEGRAM, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public ?array $testResult = null;

    public function mount(TelegramSettingsService $settings, AiSettingsService $aiSettings, TelegramAutoViewsSettingsService $autoViews): void
    {
        $this->form->fill([
            'enabled' => $settings->isEnabled(),
            'botToken' => null,
            'channelId' => $settings->getChannelId(),
            'channelCaptureEnabled' => $settings->isChannelCaptureEnabled(),
            'imageGenerationEnabled' => $settings->isImageGenerationEnabled(),
            'imageModel' => $aiSettings->getImageModel(),
            'postsPerDay' => $settings->getPostsPerDay(),
            'postSlots' => $settings->getPostSlots(),
            'signatureEnabled' => $settings->isSignatureEnabled(),
            'signatureText' => $settings->getSignatureText(),
            'blogSummaryPrompt' => $settings->getBlogSummaryPrompt(),
            'serviceAnnouncementPrompt' => $settings->getServiceAnnouncementPrompt(),
            'autoViewsEnabled' => $autoViews->isEnabled(),
            'autoViewsUpstreamId' => $autoViews->getUpstreamId(),
            'autoViewsServiceId' => $autoViews->getServiceId(),
            'autoViewsTarget' => $autoViews->getTarget(),
            'autoViewsLookbackDays' => $autoViews->getLookbackDays(),
            'autoViewsCooldownHours' => $autoViews->getCooldownHours(),
            'autoViewsMaxPostsPerRun' => $autoViews->getMaxPostsPerRun(),
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

                        Toggle::make('channelCaptureEnabled')
                            ->label('Record messages posted directly to the channel')
                            ->helperText('Independent of "Enable Telegram posting" above - watches the channel (polling Telegram every minute) and saves anything posted there outside this panel into the Queue page\'s history, so the AI knows about it too. Can only ever see messages posted after this is turned on - Telegram has no way for a bot to fetch a channel\'s older history.'),
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
                            ->live(onBlur: true)
                            ->afterStateUpdated(function ($state, Set $set, Get $get) {
                                $count = max(1, (int) $state);
                                $slots = collect($get('postSlots') ?? [])->values()->all();
                                $slots = array_slice($slots, 0, $count);

                                while (count($slots) < $count) {
                                    $slots[] = ['start' => '09:00', 'end' => '09:00'];
                                }

                                $set('postSlots', $slots);
                            })
                            ->helperText('How many blog-summary posts to keep scheduled per day, spread across the next 7 days - each gets its own send-time window below.'),

                        Toggle::make('imageGenerationEnabled')
                            ->label('Generate images with AI')
                            ->helperText('Used for service-update announcements (which have no article photo of their own) and for any blog article that has no usable image. Costs money per image via OpenAI\'s Images API - off means those posts send as text-only instead.'),

                        TextInput::make('imageModel')
                            ->label('OpenAI image model')
                            ->helperText('Always OpenAI, regardless of which provider is active for text on AI Settings/General Settings - Claude has no image API. Uses the OpenAI API key saved there.')
                            ->required(),
                    ])
                    ->columns(2),

                Section::make('Send time windows')
                    ->description('One row per post above, in order (1st post of the day, 2nd, ...). Set "From" and "To" to the same time for an exact fixed send time, or a real range to pick a random time within it - independently - each day.')
                    ->schema([
                        Repeater::make('postSlots')
                            ->label('')
                            ->addable(false)
                            ->deletable(false)
                            ->reorderable(false)
                            ->schema([
                                TimePicker::make('start')
                                    ->label('From')
                                    ->seconds(false)
                                    ->required(),

                                TimePicker::make('end')
                                    ->label('To')
                                    ->seconds(false)
                                    ->required(),
                            ])
                            ->columns(2),
                    ]),

                Section::make('Signature')
                    ->description('Appended to every outgoing post at send time - never edited into the drafts themselves, so turning this on/off or changing the text has no effect on anything already scheduled.')
                    ->schema([
                        Toggle::make('signatureEnabled')
                            ->label('Add signature to every message'),

                        Textarea::make('signatureText')
                            ->label('Signature text')
                            ->rows(2),
                    ]),

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

                Section::make('Automatic post view top-up (optional)')
                    ->description('Every 15 minutes, reads the visible counter of recent posts in your public channel. Posts below the target receive only the missing quantity. A per-post cool-down prevents duplicate orders while the provider is still delivering. This uses a private service id and never exposes it through the public free-service API.')
                    ->schema([
                        Toggle::make('autoViewsEnabled')
                            ->label('Enabled'),

                        Select::make('autoViewsUpstreamId')
                            ->label('Upstream provider')
                            ->options(fn () => GatewayUpstream::query()->where('is_active', true)->pluck('name', 'id'))
                            ->placeholder('Select an upstream provider')
                            ->helperText('Managed on Free Service Gateway > API Servers.'),

                        TextInput::make('autoViewsServiceId')
                            ->label('Service id')
                            ->placeholder('e.g. 4512')
                            ->helperText('The upstream provider\'s own id for the views service to order - ideally a drip-feed variant.'),

                        TextInput::make('autoViewsTarget')
                            ->label('Target views')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->required()
                            ->helperText('Default: 500. If a post has 320 views, the order quantity will be 180.'),

                        TextInput::make('autoViewsLookbackDays')
                            ->label('Check posts from the last (days)')
                            ->numeric()->integer()->minValue(1)->maxValue(365)->required(),

                        TextInput::make('autoViewsCooldownHours')
                            ->label('Delivery cool-down (hours)')
                            ->numeric()->integer()->minValue(1)->maxValue(168)->required()
                            ->helperText('No second order is placed for the same post during this period.'),

                        TextInput::make('autoViewsMaxPostsPerRun')
                            ->label('Maximum posts per check')
                            ->numeric()->integer()->minValue(1)->maxValue(100)->required()
                            ->helperText('Safety limit for Telegram and provider requests during one scheduler run.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(TelegramSettingsService $settings, AiSettingsService $aiSettings, TelegramAutoViewsSettingsService $autoViews): void
    {
        $data = $this->form->getState();

        $settings->setEnabled((bool) $data['enabled']);
        $settings->setBotToken($data['botToken'] ?: null);
        $settings->setChannelId($data['channelId'] ?: null);
        $settings->setChannelCaptureEnabled((bool) $data['channelCaptureEnabled']);
        $settings->setImageGenerationEnabled((bool) $data['imageGenerationEnabled']);
        $aiSettings->setImageModel($data['imageModel'] ?: null);
        $settings->setPostsPerDay((int) $data['postsPerDay']);
        $settings->setPostSlots($data['postSlots'] ?? []);
        $settings->setSignatureEnabled((bool) $data['signatureEnabled']);
        $settings->setSignatureText($data['signatureText'] ?? null);
        $settings->setBlogSummaryPrompt($data['blogSummaryPrompt']);
        $settings->setServiceAnnouncementPrompt($data['serviceAnnouncementPrompt']);
        $autoViews->setSettings(
            (bool) $data['autoViewsEnabled'],
            $data['autoViewsUpstreamId'] !== null && $data['autoViewsUpstreamId'] !== '' ? (int) $data['autoViewsUpstreamId'] : null,
            $data['autoViewsServiceId'] ?: null,
            (int) ($data['autoViewsTarget'] ?: 500),
            (int) ($data['autoViewsLookbackDays'] ?: 30),
            (int) ($data['autoViewsCooldownHours'] ?: 12),
            (int) ($data['autoViewsMaxPostsPerRun'] ?: 20),
        );

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
