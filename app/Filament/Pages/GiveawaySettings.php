<?php

namespace App\Filament\Pages;

use App\Services\GiveawaySettingsService;
use App\Services\TelegramSettingsService;
use App\Support\PanelSection;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GiveawaySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Giveaway';

    protected static ?string $navigationLabel = 'Settings';

    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.giveaway-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::GIVEAWAY, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public function mount(GiveawaySettingsService $settings): void
    {
        $this->form->fill([
            'apiBaseUrl' => $settings->getApiBaseUrl(),
            'telegramEnabled' => $settings->isTelegramEnabled(),
            'telegramBotUsername' => $settings->getTelegramBotUsername(),
            'youtubeEnabled' => $settings->isYoutubeEnabled(),
            'youtubeChannelId' => $settings->getYoutubeChannelId(),
            'youtubeSubscribeRewardAmount' => $settings->getYoutubeSubscribeRewardAmount(),
            'googleClientId' => $settings->getGoogleClientId(),
            'googleClientSecret' => null,
            'youtubeDataApiKey' => null,
            'youtubeFeaturedEnabled' => $settings->isYoutubeFeaturedEnabled(),
            'youtubeFeaturedRewardAmount' => $settings->getYoutubeFeaturedRewardAmount(),
            'youtubeVideoEnabled' => $settings->isYoutubeVideoEnabled(),
            'youtubeVideoRequiredKeyword' => $settings->getYoutubeVideoRequiredKeyword(),
            'youtubeVideoRewardAmount' => $settings->getYoutubeVideoRewardAmount(),
            'trustpilotEnabled' => $settings->isTrustpilotEnabled(),
            'trustpilotReviewUrl' => $settings->getTrustpilotReviewUrl(),
            'frontendReturnUrl' => $settings->getFrontendReturnUrl(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('API')
                    ->description('The domain this app\'s API is actually publicly reachable on - the giveaway page\'s JS calls this, and it\'s also the base for the YouTube OAuth redirect URI below. Defaults to the same domain the existing Free Service page already calls.')
                    ->schema([
                        TextInput::make('apiBaseUrl')
                            ->label('API base URL')
                            ->required()
                            ->url()
                            ->placeholder('https://core.smm.plus'),
                    ]),

                Section::make('Telegram')
                    ->description('Reuses the bot token and channel id already configured on the Telegram Channel → Settings page - only the bot\'s public @username needs to be entered again here, for the Telegram Login Widget on the giveaway page.')
                    ->schema([
                        Toggle::make('telegramEnabled')
                            ->label('Enable Telegram giveaway')
                            ->helperText(fn () => app(TelegramSettingsService::class)->hasBotToken()
                                ? null
                                : 'No Telegram bot token saved yet - set one up on Telegram Channel → Settings first.'),

                        TextInput::make('telegramBotUsername')
                            ->label('Bot @username')
                            ->placeholder('your_bot')
                            ->helperText('Also run /setdomain in @BotFather, pointed at the domain the giveaway page is served on - the Login Widget will not work otherwise.'),
                    ])
                    ->columns(2),

                Section::make('YouTube channel id')
                    ->description('The one channel identifier all three YouTube tasks below check against - its UC... id, not its @handle.')
                    ->schema([
                        TextInput::make('youtubeChannelId')
                            ->label('YouTube channel id')
                            ->placeholder('UCxxxxxxxxxxxxxxxxxxxxxx'),
                    ]),

                Section::make('YouTube — 1. Subscribe')
                    ->description('Checked via Google OAuth (subscription status is private, only the subscriber\'s own consent can confirm it). Requires a Google Cloud project with the YouTube Data API v3 enabled and OAuth 2.0 credentials. Add the redirect URI shown below as an authorized redirect URI in that project. While the OAuth app is unverified with Google, only accounts added as test users in the Google Cloud console can complete this - fine for early traction, submit for verification once the feature is proven.')
                    ->schema([
                        Placeholder::make('redirectUriHint')
                            ->label('Redirect URI to register with Google')
                            ->content(fn () => rtrim($this->data['apiBaseUrl'] ?? '', '/').'/api/giveaway/youtube/oauth/callback')
                            ->columnSpanFull(),

                        Toggle::make('youtubeEnabled')
                            ->label('Enable this task'),

                        TextInput::make('youtubeSubscribeRewardAmount')
                            ->label('Reward amount')
                            ->numeric()
                            ->prefix('$')
                            ->helperText('Shown as a hint when marking a claim rewarded - crediting the wallet is still done by hand.'),

                        TextInput::make('googleClientId')
                            ->label('Google OAuth client id'),

                        TextInput::make('googleClientSecret')
                            ->label('Google OAuth client secret')
                            ->password()
                            ->revealable()
                            ->helperText(fn () => app(GiveawaySettingsService::class)->hasGoogleClientSecret()
                                ? 'A secret is already saved - leave blank to keep it, or type a new one to replace it.'
                                : 'No secret saved yet.'),
                    ])
                    ->columns(2),

                Section::make('YouTube — 2. Featured channel')
                    ->description('Checked with a plain Google API key instead of OAuth - a channel\'s featured-channels list is public, so no consent screen is needed. Create an API key in the same Google Cloud project as above (APIs & Services → Credentials → Create API key), restricted to the YouTube Data API v3.')
                    ->schema([
                        TextInput::make('youtubeDataApiKey')
                            ->label('YouTube Data API key')
                            ->password()
                            ->revealable()
                            ->helperText(fn () => app(GiveawaySettingsService::class)->hasYoutubeDataApiKey()
                                ? 'A key is already saved - leave blank to keep it, or type a new one to replace it. Shared with the "Made a video" task below.'
                                : 'No key saved yet. Shared with the "Made a video" task below.')
                            ->columnSpanFull(),

                        Toggle::make('youtubeFeaturedEnabled')
                            ->label('Enable this task'),

                        TextInput::make('youtubeFeaturedRewardAmount')
                            ->label('Reward amount')
                            ->numeric()
                            ->prefix('$'),
                    ])
                    ->columns(2),

                Section::make('YouTube — 3. Made a video')
                    ->description('Also checked with the same API key above - a video\'s title/description/visibility are public. The user pastes a link to their video; it only counts if it\'s public and mentions the keyword below.')
                    ->schema([
                        Toggle::make('youtubeVideoEnabled')
                            ->label('Enable this task'),

                        TextInput::make('youtubeVideoRewardAmount')
                            ->label('Reward amount')
                            ->numeric()
                            ->prefix('$'),

                        TextInput::make('youtubeVideoRequiredKeyword')
                            ->label('Required keyword')
                            ->placeholder('smmplus')
                            ->helperText('Must appear somewhere in the video\'s title or description.')
                            ->columnSpanFull(),
                    ])
                    ->columns(2),

                Section::make('Trustpilot')
                    ->description('There\'s no public API to confirm a Trustpilot review is real, so this task works differently from the other two: the user pastes a link to their review as proof, and it lands in the Claims queue marked "needs manual check" instead of "verified" - go look at the actual review on Trustpilot before rewarding it.')
                    ->schema([
                        Toggle::make('trustpilotEnabled')
                            ->label('Enable Trustpilot giveaway'),

                        TextInput::make('trustpilotReviewUrl')
                            ->label('Review page URL')
                            ->url()
                            ->placeholder('https://www.trustpilot.com/evaluate/smm.plus')
                            ->helperText('Where the "Leave a review" button sends users.'),
                    ])
                    ->columns(2),

                Section::make('Frontend')
                    ->description('Where the YouTube OAuth flow sends the browser back to after checking the subscription - the giveaway page on the actual site.')
                    ->schema([
                        TextInput::make('frontendReturnUrl')
                            ->label('Giveaway page URL')
                            ->required()
                            ->url(),
                    ]),
            ])
            ->statePath('data');
    }

    public function save(GiveawaySettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setApiBaseUrl($data['apiBaseUrl']);
        $settings->setTelegramEnabled((bool) $data['telegramEnabled']);
        $settings->setTelegramBotUsername($data['telegramBotUsername'] ?: null);
        $settings->setYoutubeEnabled((bool) $data['youtubeEnabled']);
        $settings->setYoutubeChannelId($data['youtubeChannelId'] ?: null);
        $settings->setYoutubeSubscribeRewardAmount($data['youtubeSubscribeRewardAmount'] !== '' && $data['youtubeSubscribeRewardAmount'] !== null ? (float) $data['youtubeSubscribeRewardAmount'] : null);
        $settings->setGoogleClientId($data['googleClientId'] ?: null);
        $settings->setGoogleClientSecret($data['googleClientSecret'] ?: null);
        $settings->setYoutubeDataApiKey($data['youtubeDataApiKey'] ?: null);
        $settings->setYoutubeFeaturedEnabled((bool) $data['youtubeFeaturedEnabled']);
        $settings->setYoutubeFeaturedRewardAmount($data['youtubeFeaturedRewardAmount'] !== '' && $data['youtubeFeaturedRewardAmount'] !== null ? (float) $data['youtubeFeaturedRewardAmount'] : null);
        $settings->setYoutubeVideoEnabled((bool) $data['youtubeVideoEnabled']);
        $settings->setYoutubeVideoRequiredKeyword($data['youtubeVideoRequiredKeyword'] ?: null);
        $settings->setYoutubeVideoRewardAmount($data['youtubeVideoRewardAmount'] !== '' && $data['youtubeVideoRewardAmount'] !== null ? (float) $data['youtubeVideoRewardAmount'] : null);
        $settings->setTrustpilotEnabled((bool) $data['trustpilotEnabled']);
        $settings->setTrustpilotReviewUrl($data['trustpilotReviewUrl'] ?: null);
        $settings->setFrontendReturnUrl($data['frontendReturnUrl']);

        $this->form->fill([...$this->form->getState(), 'googleClientSecret' => null, 'youtubeDataApiKey' => null]);

        Notification::make()
            ->title('Giveaway settings saved')
            ->success()
            ->send();
    }
}
