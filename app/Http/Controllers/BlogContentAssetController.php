<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class BlogContentAssetController extends Controller
{
    // Serves any file on the "public" disk (storage/app/public/...) without needing a
    // "public/storage" symlink - there's no shell access on this host to run
    // `artisan storage:link`, so the disk's files are streamed through this route instead of
    // relying on a symlink existing. Despite the class name this isn't blog-specific - the
    // /telegram-images route (routes/web.php) reuses it as-is for Telegram post images.
    public function show(string $path): Response
    {
        $disk = Storage::disk('public');

        // Guard against escaping the disk root - normalize and reject any ".." segment.
        $normalized = str_replace('\\', '/', $path);
        if (str_contains($normalized, '..') || ! $disk->exists($normalized)) {
            abort(404);
        }

        return response($disk->get($normalized), Response::HTTP_OK)
            ->header('Content-Type', $disk->mimeType($normalized) ?: 'application/octet-stream');
    }
}
