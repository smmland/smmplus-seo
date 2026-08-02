<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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
 * anything holding secrets or user data (.env, storage/) even if a future zip's contents were
 * ever built by hand rather than `git archive`, which wouldn't include those anyway.
 */
class PanelUpdateService
{
    // Never touched by an update, regardless of what's in the zip - .env holds secrets, storage
    // holds uploaded content/logs/framework runtime state, neither is (or should be) part of a
    // source code update.
    private const PROTECTED_PREFIXES = ['storage/', '.env'];

    /**
     * @return array{ok: bool, message: string, fileCount?: int}
     */
    public function install(UploadedFile $zipFile): array
    {
        $zip = new ZipArchive();

        if ($zip->open($zipFile->getRealPath()) !== true) {
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
        }
    }

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

            foreach (self::PROTECTED_PREFIXES as $prefix) {
                if ($entry === $prefix || str_starts_with($entry, $prefix)) {
                    throw new RuntimeException("Refused: zip contains a protected path ({$entry}). Updates never touch storage/ or .env.");
                }
            }
        }
    }

    private function copyIntoApp(string $stagingDir): int
    {
        $fileCount = 0;

        foreach (File::allFiles($stagingDir) as $file) {
            $relative = $file->getRelativePathname();
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
