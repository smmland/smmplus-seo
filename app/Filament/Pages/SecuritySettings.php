<?php

namespace App\Filament\Pages;

use App\Services\GatewaySettingsService;
use App\Services\TorExitNodeListService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class SecuritySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Settings';

    protected static string $view = 'filament.pages.security-settings';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::SECURITY, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public function mount(GatewaySettingsService $settings): void
    {
        $this->form->fill([
            'autoBlockEnabled' => $settings->isAutoBlockEnabled(),
            'autoBlockThreshold' => $settings->getAutoBlockThreshold(),
            'autoBlockBaseHours' => $settings->getAutoBlockBaseHours(),
            'autoBlockMultiplier' => $settings->getAutoBlockMultiplier(),
            'autoBlockMaxHours' => $settings->getAutoBlockMaxHours(),
            'cpanelBlockerEnabled' => $settings->isCpanelBlockerEnabled(),
            'cpanelHost' => $settings->getCpanelHost(),
            'cpanelUsername' => $settings->getCpanelUsername(),
            'cpanelApiToken' => null,
            'torBlockingEnabled' => $settings->isTorBlockingEnabled(),
            'torBlockDays' => $settings->getTorBlockDays(),
        ]);
    }

    public function refreshTorList(TorExitNodeListService $torExitNodes): void
    {
        $count = $torExitNodes->refresh();

        $notification = Notification::make()
            ->title($count > 0 ? "Tor exit node list refreshed: {$count} IPs." : 'Refresh failed - see the logs for details.');

        $count > 0 ? $notification->success() : $notification->danger();

        $notification->send();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Automatic IP blocking')
                    ->description('Blocks an IP once it exceeds the daily request threshold, sends more than 3 requests in a minute, or sends an absurdly oversized request - with the block duration doubling (or your chosen multiplier) each time the IP offends again. Applies to the Free Service Gateway API.')
                    ->schema([
                        Toggle::make('autoBlockEnabled')
                            ->label('Enabled')
                            ->helperText('The daily-threshold check runs automatically every 5 minutes; the per-minute-flood and oversized-payload checks apply instantly. Off by default until you review the threshold below.'),

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
                    ->description('When set, every auto-block above is also registered with cPanel\'s own IP Blocker, so the IP gets rejected by the web server itself instead of ever reaching PHP - this is what actually stops a flood from exhausting the account\'s process limit. Generate a token from cPanel > Security > Manage API Tokens (no root/WHM access needed).')
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

                Section::make('Tor exit node blocking')
                    ->description('Rejects any gateway request coming from a known Tor exit node IP. Unlike the auto-block above, this checks a maintained list rather than escalating per-IP - abuse routed through Tor uses a different exit IP every few requests, so blocking one at a time never catches up. The list refreshes hourly on its own. Only IPs that actually make a request get registered in Blocked IPs / cPanel (below) - not the whole list - and clear themselves automatically once their block expires.')
                    ->schema([
                        Toggle::make('torBlockingEnabled')
                            ->label('Enabled'),

                        TextInput::make('torBlockDays')
                            ->label('Block duration (days)')
                            ->numeric()
                            ->minValue(1)
                            ->required()
                            ->helperText('How long a Tor exit IP that actually hit the gateway stays blocked (in Blocked IPs and, if configured, cPanel) before automatically clearing - checked every 5 minutes, same sweep as the auto-block above.'),

                        Placeholder::make('torListStatus')
                            ->label('Current list')
                            ->content(function (): string {
                                $torExitNodes = app(TorExitNodeListService::class);
                                $count = $torExitNodes->count();
                                $refreshedAt = $torExitNodes->lastRefreshedAt();

                                if ($count === 0 || ! $refreshedAt) {
                                    return 'Not fetched yet - click "Refresh Tor list now" below.';
                                }

                                return "{$count} exit node IPs, last refreshed {$refreshedAt->diffForHumans()}.";
                            }),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(GatewaySettingsService $settings): void
    {
        $data = $this->form->getState();

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
        $settings->setTorBlockingSettings((bool) $data['torBlockingEnabled'], (int) $data['torBlockDays']);

        $this->form->fill([...$this->form->getState(), 'cpanelApiToken' => null]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
