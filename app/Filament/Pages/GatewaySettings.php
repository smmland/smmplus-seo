<?php

namespace App\Filament\Pages;

use App\Services\GatewaySettingsService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GatewaySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Settings';

    // Pushed to the end of the group - everything else in the group defaults to sort -1.
    protected static ?int $navigationSort = 100;

    protected static string $view = 'filament.pages.gateway-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::FREE_SERVICE, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public function mount(GatewaySettingsService $settings): void
    {
        $this->form->fill([
            'allowedOrigins' => $settings->getAllowedOrigins(),
            'globalDailySeconds' => $settings->getGlobalDailySeconds(),
            'globalDailyIpLimit' => $settings->getGlobalDailyIpLimit(),
            'globalDailyTargetLimit' => $settings->getGlobalDailyTargetLimit(),
            'autoBlockEnabled' => $settings->isAutoBlockEnabled(),
            'autoBlockThreshold' => $settings->getAutoBlockThreshold(),
            'autoBlockBaseHours' => $settings->getAutoBlockBaseHours(),
            'autoBlockMultiplier' => $settings->getAutoBlockMultiplier(),
            'autoBlockMaxHours' => $settings->getAutoBlockMaxHours(),
            'cpanelBlockerEnabled' => $settings->isCpanelBlockerEnabled(),
            'cpanelHost' => $settings->getCpanelHost(),
            'cpanelUsername' => $settings->getCpanelUsername(),
            'cpanelApiToken' => null,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('CORS & global limits')
                    ->schema([
                        TagsInput::make('allowedOrigins')
                            ->label('Allowed origins')
                            ->placeholder('https://smm.plus')
                            ->helperText('Only requests with an Origin header matching one of these are allowed to call the gateway from a browser.')
                            ->required(),

                        TextInput::make('globalDailySeconds')
                            ->label('Global daily window (seconds)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('How long the global per-IP/per-target daily caps below apply for. Defaults to 86400 (24 hours).'),

                        TextInput::make('globalDailyIpLimit')
                            ->label('Global daily limit per IP')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Max free orders (across all services) one IP can place within the window, regardless of quantity.'),

                        TextInput::make('globalDailyTargetLimit')
                            ->label('Global daily limit per target')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Max free orders (across all services) one link/username can receive within the window.'),
                    ]),

                Section::make('Automatic IP blocking')
                    ->description('Blocks an IP once it exceeds the daily request threshold, with the block duration doubling (or your chosen multiplier) each time the IP offends again.')
                    ->schema([
                        Toggle::make('autoBlockEnabled')
                            ->label('Enabled')
                            ->helperText('Runs automatically every 5 minutes. Off by default until you review the threshold below.'),

                        TextInput::make('autoBlockThreshold')
                            ->label('Daily request threshold')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('An IP sending this many or more requests in a rolling 24 hours gets blocked. Requests already rejected for being blocked or off-origin don\'t count.'),

                        TextInput::make('autoBlockBaseHours')
                            ->label('First block duration (hours)')
                            ->numeric()
                            ->minValue(0.5)
                            ->step(0.5)
                            ->required(),

                        TextInput::make('autoBlockMultiplier')
                            ->label('Escalation multiplier')
                            ->numeric()
                            ->minValue(1)
                            ->step(0.5)
                            ->required()
                            ->helperText('Each repeat offense multiplies the previous block duration by this. 2 = 1h, 2h, 4h, 8h...'),

                        TextInput::make('autoBlockMaxHours')
                            ->label('Maximum block duration (hours)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('Caps how long a single auto-block can last, no matter how high the offense count climbs.'),
                    ])
                    ->columns(2),

                Section::make('cPanel IP Blocker (optional)')
                    ->description('When set, every auto-block above is also registered with cPanel\'s own IP Blocker, so the IP gets rejected by the web server itself instead of reaching PHP - this is what actually stops a flood from exhausting the account\'s process limit. Generate a token from cPanel > Security > Manage API Tokens (no root/WHM access needed).')
                    ->schema([
                        Toggle::make('cpanelBlockerEnabled')
                            ->label('Enabled'),

                        TextInput::make('cpanelHost')
                            ->label('cPanel host')
                            ->placeholder('server123.example.com:2083')
                            ->helperText('The host:port you log into cPanel with - not necessarily this site\'s own domain.'),

                        TextInput::make('cpanelUsername')
                            ->label('cPanel username'),

                        TextInput::make('cpanelApiToken')
                            ->label('API token')
                            ->password()
                            ->revealable()
                            ->helperText(fn () => app(GatewaySettingsService::class)->hasCpanelApiToken()
                                ? 'A token is already saved - leave blank to keep it, or type a new one to replace it.'
                                : 'No token saved yet.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(GatewaySettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setAllowedOrigins($data['allowedOrigins']);
        $settings->setGlobalDailyLimits(
            (int) $data['globalDailySeconds'],
            (int) $data['globalDailyIpLimit'],
            (int) $data['globalDailyTargetLimit'],
        );
        $settings->setAutoBlockSettings(
            (bool) $data['autoBlockEnabled'],
            (int) $data['autoBlockThreshold'],
            (float) $data['autoBlockBaseHours'],
            (float) $data['autoBlockMultiplier'],
            (float) $data['autoBlockMaxHours'],
        );
        $settings->setCpanelBlockerSettings(
            (bool) $data['cpanelBlockerEnabled'],
            $data['cpanelHost'],
            $data['cpanelUsername'],
            $data['cpanelApiToken'] ?: null,
        );

        $this->form->fill([...$this->form->getState(), 'cpanelApiToken' => null]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
