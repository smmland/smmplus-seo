<?php

namespace App\Filament\Pages;

use App\Services\SettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'General Settings';

    protected static ?string $title = 'General Settings';

    protected static string $view = 'filament.pages.general-settings';

    public ?array $data = [];

    public string $accentColor = '';

    // How stale the heartbeat (written every minute by routes/console.php) can be before we
    // call the cron dead rather than just between ticks - generous enough to absorb a slow
    // request or two without flapping.
    private const CRON_STALE_AFTER_MINUTES = 3;

    // Reached from the account menu (top-right avatar -> Settings) instead of the sidebar -
    // see AdminPanelProvider::panel()'s userMenuItems().
    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    public function mount(SettingsService $settings): void
    {
        $this->form->fill([
            'email' => Auth::user()->email,
        ]);

        $this->accentColor = $settings->getAccentColorKey();
    }

    public function getAccentColorPresets(): array
    {
        return SettingsService::ACCENT_COLOR_PRESETS;
    }

    // The panel's colors (nav, buttons, ...) are resolved into CSS variables once per full page
    // load (AdminPanelProvider::panel(), read server-side into the layout's <head>) - a Livewire
    // property update alone can't repaint chrome that's already on the page, so this forces a
    // real browser navigation instead of a SPA-style partial update.
    public function setAccentColor(string $key, SettingsService $settings): void
    {
        $settings->setAccentColor($key);

        Notification::make()
            ->title('Panel color updated')
            ->success()
            ->send();

        $this->redirect(static::getUrl());
    }

    public function getCronStatus(SettingsService $settings): array
    {
        $heartbeat = $settings->getCronHeartbeatAt();

        return [
            'heartbeat' => $heartbeat,
            'active' => $heartbeat !== null && $heartbeat->gt(now()->subMinutes(self::CRON_STALE_AFTER_MINUTES)),
        ];
    }

    // Every update this panel ships that changes the database needs `php artisan migrate` run
    // once after deploying it - normally a one-line SSH command, but this host's admin has no
    // terminal access at all, only FTP/cPanel file upload. This button is the only way those
    // updates can ever actually take effect: upload the new files, then click this instead of
    // needing shell access. Safe to click any time, including with nothing pending - already-
    // applied migrations are tracked and skipped automatically.
    public function pendingMigrationsCount(): int
    {
        $migrator = app('migrator');
        $files = $migrator->getMigrationFiles(database_path('migrations'));
        $ran = $migrator->getRepository()->getRan();

        return count(array_diff(array_keys($files), $ran));
    }

    public function runMigrations(): void
    {
        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        Notification::make()
            ->title('Database updated')
            ->body($output !== '' ? $output : 'Nothing to update - already up to date.')
            ->success()
            ->send();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('email')
                    ->label('Admin email')
                    ->email()
                    ->required(),

                TextInput::make('newPassword')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->helperText('Leave this field empty if you don\'t want to change the password.'),

                TextInput::make('currentPassword')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Enter your current password to confirm these changes.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Auth::user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            Notification::make()
                ->title('Current password is incorrect')
                ->danger()
                ->send();

            return;
        }

        $user->email = $data['email'];

        if (filled($data['newPassword'] ?? null)) {
            $user->password = $data['newPassword'];
        }

        $user->save();

        $this->form->fill([
            'email' => $user->email,
            'newPassword' => null,
            'currentPassword' => null,
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
