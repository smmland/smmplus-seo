<?php

namespace App\Services;

use App\Models\BlogTranslationJob;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use ZipArchive;

/**
 * Installs a code update from an uploaded zip (a `git archive` of this repo, as sent by whoever
 * built the update) directly onto this app's own files - the only way updates can ever reach
 * this server at all, since its admin has no terminal/SSH access, only file upload via this
 * panel or cPanel's file manager.
 *
 * This is inherently a self-modifying-code operation, so the two things that matter most are:
 * never letting an entry write outside the app root (path traversal), and never letting it touch
 * anything holding secrets or user data (.env, storage/). A `git archive` of this repo always
 * contains a handful of tracked placeholder files under storage/ (the .gitignore files that keep
 * otherwise-empty runtime directories in git) - those are harmless, so protected paths are
 * silently skipped during install rather than rejecting the whole update over their presence.
 * Only a genuinely unsafe entry (path traversal, absolute path, null byte) aborts the install.
 */
class PanelUpdateService
{
    // Skipped during install, regardless of what's in the zip - storage holds uploaded
    // content/logs/framework runtime state and isn't (or shouldn't be) part of a source code
    // update; .env holds secrets. A normal `git archive` includes neither the real .env nor any
    // real storage/ content, only inert .gitignore placeholders, so skipping these never drops
    // anything an update actually needs to ship.
    private const PROTECTED_PREFIXES = ['storage/'];

    public function __construct(private SettingsService $settings) {}

    /**
     * @return array{ok: bool, message: string, fileCount?: int}
     */
    public function install(UploadedFile $zipFile): array
    {
        if ($this->hasBackgroundWorkRunning()) {
            return [
                'ok' => false,
                'message' => 'A background translation job is currently queued or running - wait for it to finish (check the Blog Translation page) before installing an update, so the file swap can\'t interrupt it mid-run.',
            ];
        }

        if (! $this->settings->acquirePanelUpdateLock()) {
            return ['ok' => false, 'message' => 'Another update is already being installed - wait for it to finish.'];
        }

        $zip = new ZipArchive();

        if ($zip->open($zipFile->getRealPath()) !== true) {
            $this->settings->releasePanelUpdateLock();

            return ['ok' => false, 'message' => 'Could not open that file as a zip archive.'];
        }

        $stagingDir = storage_path('app/panel-update-'.uniqid());

        try {
            $this->assertEntriesAreSafe($zip);

            File::ensureDirectoryExists($stagingDir);
            $zip->extractTo($stagingDir);
            $zip->close();

            $fileCount = $this->copyIntoApp($stagingDir);

            $this->bustCaches();

            return ['ok' => true, 'message' => "{$fileCount} file(s) installed.", 'fileCount' => $fileCount];
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            if (File::isDirectory($stagingDir)) {
                File::deleteDirectory($stagingDir);
            }

            $this->settings->releasePanelUpdateLock();
        }
    }

    // The only background work this app has is queued blog translations (routes/console.php's
    // queue:work tick) - refusing to start an update while one's in flight avoids the file swap
    // landing mid-job and corrupting its result. Guarded the same way as everywhere else that
    // touches this table: it might not exist yet on a host that hasn't clicked "Update database"
    // since this feature shipped.
    private function hasBackgroundWorkRunning(): bool
    {
        if (! Schema::hasTable('blog_translation_jobs')) {
            return false;
        }

        return BlogTranslationJob::query()
            ->whereIn('status', BlogTranslationJob::PENDING_STATUSES)
            ->exists();
    }

    // Only rejects the whole zip for entries that could actually cause harm if extracted -
    // writing outside the app root. Protected paths (storage/, .env) are handled separately, by
    // skipping them at copy time instead of aborting here.
    private function assertEntriesAreSafe(ZipArchive $zip): void
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->getNameIndex($i);

            if ($entry === false) {
                continue;
            }

            if (str_contains($entry, '..') || str_starts_with($entry, '/') || str_contains($entry, "\0")) {
                throw new RuntimeException("Refused: unsafe path in zip ({$entry}).");
            }
        }
    }

    private function isProtectedPath(string $relative): bool
    {
        foreach (self::PROTECTED_PREFIXES as $prefix) {
            if ($relative === $prefix || str_starts_with($relative, $prefix)) {
                return true;
            }
        }

        // Exact filename match only - unlike a prefix check, this doesn't also catch harmless
        // files like .env.example or .env.testing that merely start with ".env" but hold no
        // real secrets.
        return $relative === '.env' || str_ends_with($relative, '/.env');
    }

    private function copyIntoApp(string $stagingDir): int
    {
        $fileCount = 0;

        foreach (File::allFiles($stagingDir) as $file) {
            $relative = $file->getRelativePathname();

            if ($this->isProtectedPath($relative)) {
                continue;
            }

            $destination = base_path($relative);

            File::ensureDirectoryExists(dirname($destination));
            File::copy($file->getPathname(), $destination);
            $fileCount++;
        }

        return $fileCount;
    }

    // Clears every cache that could otherwise keep serving the old code/views after this - most
    // importantly opcache, since PHP's own bytecode cache has no idea these files just changed
    // underneath it and would otherwise keep running the old version until it expires on its own.
    private function bustCaches(): void
    {
        Artisan::call('view:clear');
        Artisan::call('config:clear');
        Artisan::call('cache:clear');

        if (function_exists('opcache_reset')) {
            opcache_reset();
        }
    }
}
