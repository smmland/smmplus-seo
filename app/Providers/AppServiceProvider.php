<?php

namespace App\Providers;

use App\Services\ActivityLogService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->ensureLivewireUploadDirIsWritable();
        $this->logAuthEvents();
    }

    // Every panel login/logout, in the same audit trail as the actions taken during that
    // session - lets an admin answer "who was even signed in when this happened", not just
    // "what changed".
    private function logAuthEvents(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            app(ActivityLogService::class)->record('auth.login', $event->user, subjectLabel: $event->user->email ?? null);
        });

        Event::listen(Logout::class, function (Logout $event): void {
            if (! $event->user) {
                return;
            }

            app(ActivityLogService::class)->record('auth.logout', $event->user, subjectLabel: $event->user->email ?? null);
        });
    }

    /**
     * On a shared host with no terminal access, storage/app/.../livewire-tmp's permissions are
     * whatever they ended up as the moment it was first created (a fresh deploy, an FTP upload, a
     * zip extractor) - and PanelUpdateService deliberately never touches anything under storage/
     * on any update, so a too-restrictive permission set here (found locally as 0700, owner-only -
     * plausible on a host where the web server runs as a different user than whatever created the
     * directory) would otherwise stay broken forever with no way for the admin to fix it
     * themselves. Every Livewire file upload in this app - most visibly, "Install from zip" in
     * General Settings - writes here first, so this silently breaks that upload (the file never
     * actually arrives, so the property backing it stays empty and "Install update" never
     * enables) with no error message pointing at the real cause. Cheap and safe to re-check on
     * every boot: does nothing once it's already correct, and never allowed to break the app
     * from booting if something about this probe itself goes wrong.
     */
    private function ensureLivewireUploadDirIsWritable(): void
    {
        $diskName = config('livewire.temporary_file_upload.disk') ?: config('filesystems.default');

        if (config("filesystems.disks.{$diskName}.driver") !== 'local') {
            return;
        }

        try {
            $directory = config('livewire.temporary_file_upload.directory') ?: 'livewire-tmp';
            $path = Storage::disk($diskName)->path($directory);

            if (! File::isDirectory($path)) {
                File::makeDirectory($path, 0775, true);

                return;
            }

            if ((fileperms($path) & 0777) !== 0775) {
                @chmod($path, 0775);
            }
        } catch (Throwable $e) {
            // Never let this probe break the app from booting - worst case, uploads stay broken
            // and installUpdate()'s own validation error is what the admin sees instead.
        }
    }
}
