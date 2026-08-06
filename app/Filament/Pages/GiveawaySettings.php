<?php

namespace App\Filament\Pages;

use App\Services\GiveawaySettingsService;
use App\Services\TelegramSettingsService;
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

    public ?array $data = [];

    public function mount(GiveawaySettingsService $settings): void
    {
        $this->form->fill([
            'apiBaseUrl' => $settings->getApiBaseUrl(),
            'telegramEnabled' => $settings->isTelegramEnabled(),
            'telegramBotUsername' => $settings->getTelegramBotUsername(),
            'youtubeEnabled' => $settings->isYoutubeEnabled(),
            'youtubeChannelId' => $settings->getYoutubeChannelId(),
            'googleClientId' => $settings->getGoogleClientId(),
            'googleClientSecret' => null,
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

                Section::make('YouTube')
                    ->description('Requires a Google Cloud project with the YouTube Data API v3 enabled and OAuth 2.0 credentials. Add the redirect URI shown below as an authorized redirect URI in that project. While the OAuth app is unverified with Google, only accounts added as test users in the Google Cloud console can complete this - fine for early traction, submit for verification once the feature is proven.')
                    ->schema([
                        Placeholder::make('redirectUriHint')
                            ->label('Redirect URI to register with Google')
                            ->content(fn () => rtrim($this->data['apiBaseUrl'] ?? '', '/').'/api/giveaway/youtube/oauth/callback')
                            ->columnSpanFull(),

                        Toggle::make('youtubeEnabled')
                            ->label('Enable YouTube giveaway'),

                        TextInput::make('youtubeChannelId')
                            ->label('YouTube channel id')
                            ->placeholder('UCxxxxxxxxxxxxxxxxxxxxxx')
                            ->helperText('The channel users need to subscribe to - its UC... id, not its @handle.'),

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
        $settings->setGoogleClientId($data['googleClientId'] ?: null);
        $settings->setGoogleClientSecret($data['googleClientSecret'] ?: null);
        $settings->setTrustpilotEnabled((bool) $data['trustpilotEnabled']);
        $settings->setTrustpilotReviewUrl($data['trustpilotReviewUrl'] ?: null);
        $settings->setFrontendReturnUrl($data['frontendReturnUrl']);

        $this->form->fill([...$this->form->getState(), 'googleClientSecret' => null]);

        Notification::make()
            ->title('Giveaway settings saved')
            ->success()
            ->send();
    }
}
