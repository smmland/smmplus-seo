<?php

namespace App\Filament\Pages;

use App\Services\PanelUpdateService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Artisan;
use Livewire\WithFileUploads;

/**
 * Moved out of General Settings into its own page, reached from the account menu (top-right
 * avatar, next to Settings/AI Costs/Sign out - see AdminPanelProvider::panel()'s
 * userMenuItems()) instead of the sidebar, the same way AiCosts and HiddenTranslations are.
 */
class PanelUpdate extends Page
{
    use WithFileUploads;

    protected static ?string $title = 'Update';

    protected static string $view = 'filament.pages.panel-update';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    // Deliberately not a grantable PanelSection like the other pages - installing an update
    // replaces the app's own code, so handing that out is equivalent to handing out full access
    // regardless of what else a user's permissions say.
    public static function canAccess(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    // Zip is whatever `git archive` produces - the same file sent for every update. Livewire
    // handles the actual upload (to a temp disk) via $updateZip; this just hands the saved
    // temp file to PanelUpdateService once "Install update" is clicked, rather than acting on
    // every keystroke/selection change the way a live-validated form field would.
    public $updateZip = null;

    // Read straight from this app's own files each render, not cached anywhere - always
    // reflects whatever was actually installed last, including right after installUpdate() below
    // swaps the files in.
    public function panelVersion(): ?string
    {
        return app(PanelUpdateService::class)->currentVersion();
    }

    public function panelNotes(): ?string
    {
        return app(PanelUpdateService::class)->currentNotes();
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
        // Route files can change in an update even when no migration is pending. Clearing here
        // also gives cPanel-only admins a post-install way to activate a newly-added route when
        // the previous version of PanelUpdateService was still loaded during the file swap.
        Artisan::call('route:clear');
        Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());

        Notification::make()
            ->title('Database updated')
            ->body($output !== '' ? $output : 'Nothing to update - already up to date.')
            ->success()
            ->send();
    }

    public function installUpdate(PanelUpdateService $updater): void
    {
        $this->validate([
            'updateZip' => 'required|file|mimes:zip|max:51200',
        ]);

        $result = $updater->install($this->updateZip);

        $notification = Notification::make()
            ->title($result['ok'] ? 'Panel updated' : 'Update failed')
            ->body($result['ok']
                ? $result['message'].' If this update included a database change, click "Update database" below too.'
                : $result['message']);

        $result['ok'] ? $notification->success() : $notification->danger();
        $notification->send();

        $this->updateZip = null;
    }
}
