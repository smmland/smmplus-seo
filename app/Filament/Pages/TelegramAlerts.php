<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardsSectionEdits;
use App\Models\TelegramAlertRecipient;
use App\Services\TelegramAlertSettingsService;
use App\Services\TelegramBotService;
use App\Services\TelegramSettingsService;
use App\Support\PanelSection;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;

/**
 * Personal DM alerts (Telegram Channel > Alerts, reached from the account menu next to
 * Settings/AI Costs/Update - see AdminPanelProvider::panel()'s userMenuItems()) - entirely
 * separate from the channel-posting bot's own settings page even though it reuses the same bot
 * token, since who gets DMed and about what is a different concern from what goes out to the
 * public channel.
 */
class TelegramAlerts extends Page implements HasForms
{
    use GuardsSectionEdits;
    use InteractsWithForms;

    protected static ?string $title = 'Alerts';

    protected static string $view = 'filament.pages.telegram-alerts';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::TELEGRAM, PanelSection::TIER_SETTINGS)) ?? false;
    }

    public ?array $data = [];

    public string $newRecipientLabel = '';

    public function mount(TelegramAlertSettingsService $settings): void
    {
        $this->form->fill([
            'enabled' => $settings->isEnabled(),
            'onNewService' => $settings->isOnNewServiceEnabled(),
            'onServiceChanged' => $settings->isOnServiceChangedEnabled(),
            'onNewText' => $settings->isOnNewTextEnabled(),
            'onPostPreview' => $settings->isOnPostPreviewEnabled(),
            'previewMinutesBefore' => $settings->getPreviewMinutesBefore(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Personal alerts')
                    ->description('Sent as a direct message from the same bot Telegram Channel > Settings uses, to everyone linked below - independent of whether channel posting itself is on.')
                    ->schema([
                        Toggle::make('enabled')
                            ->label('Enable personal alerts')
                            ->live()
                            ->helperText('While off, nothing below is ever sent, regardless of the toggles.'),

                        Toggle::make('onNewService')
                            ->label('A new service was added'),

                        Toggle::make('onServiceChanged')
                            ->label('An existing service changed and needs translation'),

                        Toggle::make('onNewText')
                            ->label('New content was added and needs translation'),

                        Toggle::make('onPostPreview')
                            ->label('Preview a channel post before it sends')
                            ->live(),

                        TextInput::make('previewMinutesBefore')
                            ->label('Minutes before sending')
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(1440)
                            ->required()
                            ->visible(fn (Get $get) => $get('onPostPreview'))
                            ->helperText('How long before a post actually goes out to the channel its text+image preview gets DMed to you.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    public function save(TelegramAlertSettingsService $settings): void
    {
        if (! $this->assertCanEdit(PanelSection::TELEGRAM)) {
            return;
        }

        $data = $this->form->getState();

        $settings->setEnabled((bool) $data['enabled']);
        $settings->setOnNewServiceEnabled((bool) $data['onNewService']);
        $settings->setOnServiceChangedEnabled((bool) $data['onServiceChanged']);
        $settings->setOnNewTextEnabled((bool) $data['onNewText']);
        $settings->setOnPostPreviewEnabled((bool) $data['onPostPreview']);
        $settings->setPreviewMinutesBefore((int) $data['previewMinutesBefore']);

        Notification::make()
            ->title('Alert settings saved')
            ->success()
            ->send();
    }

    #[Computed]
    public function recipientsTableReady(): bool
    {
        return Schema::hasTable('telegram_alert_recipients');
    }

    #[Computed]
    public function recipients()
    {
        if (! $this->recipientsTableReady()) {
            return collect();
        }

        return TelegramAlertRecipient::query()->latest()->get();
    }

    // No service type-hint here - unlike a Livewire lifecycle hook or a route action, a plain
    // "$this->botUsername()" call from the blade view doesn't get the container's argument
    // resolution, so this resolves it manually instead.
    public function botUsername(): ?string
    {
        return app(TelegramBotService::class)->getBotUsername();
    }

    public function linkUrlFor(TelegramAlertRecipient $recipient): ?string
    {
        $username = $this->botUsername();

        if (! $username) {
            return null;
        }

        return "https://t.me/{$username}?start={$recipient->link_token}";
    }

    public function addRecipient(): void
    {
        if (! $this->assertCanEdit(PanelSection::TELEGRAM)) {
            return;
        }

        $label = trim($this->newRecipientLabel);

        if ($label === '') {
            Notification::make()->title('Type a name for this recipient first')->warning()->send();

            return;
        }

        TelegramAlertRecipient::create([
            'label' => $label,
            'link_token' => TelegramAlertRecipient::generateLinkToken(),
        ]);

        $this->newRecipientLabel = '';

        unset($this->recipients);

        Notification::make()
            ->title('Recipient added')
            ->body('Open their link below with Telegram and send /start to finish connecting them.')
            ->success()
            ->send();
    }

    public function removeRecipient(int $recipientId): void
    {
        if (! $this->assertCanEdit(PanelSection::TELEGRAM)) {
            return;
        }

        TelegramAlertRecipient::query()->where('id', $recipientId)->delete();

        unset($this->recipients);
    }
}
