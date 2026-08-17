<?php

namespace App\Filament\Pages;

use App\Models\GatewayBlockedIp;
use App\Services\CpanelIpBlockerService;
use App\Services\GatewaySettingsService;
use App\Services\LoginSecuritySettingsService;
use App\Services\TorExitNodeListService;
use App\Support\PanelSection;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Get;
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

    public function mount(GatewaySettingsService $settings, LoginSecuritySettingsService $loginSecurity): void
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
            'cpanelHtaccessPath' => $settings->getCpanelHtaccessPath(),
            'autoSyncBlockedIps' => $settings->isAutoSyncBlockedIpsEnabled(),
            'torBlockingEnabled' => $settings->isTorBlockingEnabled(),
            'torBlockDays' => $settings->getTorBlockDays(),
            'recaptchaEnabled' => $loginSecurity->isRecaptchaEnabled(),
            'recaptchaSiteKey' => $loginSecurity->getRecaptchaSiteKey(),
            'recaptchaSecretKey' => null,
            'recaptchaFailureThreshold' => $loginSecurity->getFailureThreshold(),
            'recaptchaFailureWindowMinutes' => $loginSecurity->getFailureWindowMinutes(),
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

    // Proactively blocks every currently-known exit-node IP, not just ones that happen to hit
    // the gateway (the reactive per-request Tor block already handles that). Writes the whole
    // list into cPanel's .htaccess directly (CpanelIpBlockerService::addIpsToHtaccess) - one read
    // and one write total, regardless of list size - rather than one add_ip API call per IP:
    // cPanel's own BlockIP API has no bulk/list endpoint, so calling it once per IP (even
    // batched a few at a time) means thousands of round trips for a full exit-node list, which
    // both takes far longer and is far more likely to trip cPanel's own API rate limiting than
    // just rewriting the file once.
    public function blockAllTorExitNodes(TorExitNodeListService $torExitNodes, CpanelIpBlockerService $cpanel, GatewaySettingsService $settings): void
    {
        $ips = collect($torExitNodes->all());

        if ($ips->isEmpty()) {
            Notification::make()
                ->title('The Tor exit node list is empty - click "Refresh Tor list now" first.')
                ->danger()
                ->send();

            return;
        }

        $result = $cpanel->addIpsToHtaccess($ips->all());

        if (! $result['ok']) {
            Notification::make()
                ->title("Couldn't update cPanel's .htaccess")
                ->body($result['error'])
                ->danger()
                ->send();

            return;
        }

        if ($result['added'] === 0) {
            Notification::make()
                ->title('Every known Tor exit node IP is already blocked in cPanel\'s .htaccess.')
                ->success()
                ->send();

            return;
        }

        // Local bookkeeping so this panel's own Blocked IPs / cPanel Blocked IPs pages reflect
        // it - the .htaccess write above already succeeded for the whole list at this point, so
        // every one of these is confirmed blocked, not just the newly-added ones.
        $now = now();
        $days = $settings->getTorBlockDays();
        $blockedUntil = $now->copy()->addDays($days);

        $ips->chunk(500)->each(function ($chunk) use ($blockedUntil, $days, $now) {
            $rows = $chunk->map(fn (string $ip) => [
                'ip' => $ip,
                'note' => "Tor exit-node bulk block (expires in {$days}d)",
                'is_active' => true,
                'blocked_until' => $blockedUntil,
                'offense_count' => 0,
                'cpanel_synced_at' => $now,
                'cpanel_sync_error' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            GatewayBlockedIp::query()->upsert(
                $rows,
                ['ip'],
                ['note', 'is_active', 'blocked_until', 'cpanel_synced_at', 'cpanel_sync_error', 'updated_at'],
            );
        });

        $alreadyPresent = $ips->count() - $result['added'];

        Notification::make()
            ->title("Blocked {$result['added']} new Tor exit node IP(s) directly in cPanel's .htaccess.")
            ->body($alreadyPresent > 0 ? "{$alreadyPresent} were already blocked there." : null)
            ->success()
            ->send();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Panel login reCAPTCHA')
                    ->description('Shows Google reCAPTCHA v2 only after repeated failed panel login attempts. Failed attempts are tracked by both IP address and email, then expire automatically after the configured window.')
                    ->schema([
                        Toggle::make('recaptchaEnabled')
                            ->label('Enabled')
                            ->live()
                            ->helperText('Create a reCAPTCHA v2 Checkbox key for the panel domain in Google reCAPTCHA Admin, then enter both keys below.'),

                        TextInput::make('recaptchaSiteKey')
                            ->label('Site key')
                            ->required(fn (Get $get): bool => (bool) $get('recaptchaEnabled'))
                            ->maxLength(255),

                        TextInput::make('recaptchaSecretKey')
                            ->label('Secret key')
                            ->password()
                            ->revealable()
                            ->required(fn (Get $get): bool => (bool) $get('recaptchaEnabled') && ! app(LoginSecuritySettingsService::class)->hasRecaptchaSecretKey())
                            ->helperText(fn () => app(LoginSecuritySettingsService::class)->hasRecaptchaSecretKey()
                                ? 'A secret key is already saved securely - leave blank to keep it, or type a new one to replace it.'
                                : 'No secret key saved yet.'),

                        TextInput::make('recaptchaFailureThreshold')
                            ->label('Failed attempts before reCAPTCHA')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(20)
                            ->required(),

                        TextInput::make('recaptchaFailureWindowMinutes')
                            ->label('Failure tracking window (minutes)')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->helperText('A successful login clears the counters immediately.'),
                    ])
                    ->columns(2),

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

                        TextInput::make('cpanelHtaccessPath')
                            ->label('.htaccess path')
                            ->placeholder('public_html/.htaccess')
                            ->helperText('Relative to your cPanel account\'s home directory. cPanel\'s API can add/remove a block but never list what\'s currently blocked - this tells the "cPanel Blocked IPs" page (Security menu) which file to read cPanel\'s own "deny from" rules from. Usually public_html/.htaccess for the account\'s main domain.'),

                        Toggle::make('autoSyncBlockedIps')
                            ->label('Auto-sync blocked IPs to .htaccess')
                            ->helperText('Every 5 minutes, adds any actively-blocked IP (auto-block, manual, Tor) that\'s missing from cPanel\'s .htaccess - straight into the file, not one API call per IP. Off by default: blocking an IP already registers it with cPanel right away, so this only matters if that sync can fail and go unnoticed (e.g. cPanel was down at the time, or got configured after IPs were already blocked).')
                            ->columnSpanFull(),
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

    public function save(GatewaySettingsService $settings, LoginSecuritySettingsService $loginSecurity): void
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
            $data['cpanelHtaccessPath'],
        );
        $settings->setAutoSyncBlockedIpsEnabled((bool) $data['autoSyncBlockedIps']);
        $settings->setTorBlockingSettings((bool) $data['torBlockingEnabled'], (int) $data['torBlockDays']);
        $loginSecurity->setRecaptchaSettings(
            (bool) $data['recaptchaEnabled'],
            $data['recaptchaSiteKey'],
            $data['recaptchaSecretKey'] ?: null,
            (int) $data['recaptchaFailureThreshold'],
            (int) $data['recaptchaFailureWindowMinutes'],
        );

        $this->form->fill([
            ...$this->form->getState(),
            'cpanelApiToken' => null,
            'recaptchaSecretKey' => null,
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
