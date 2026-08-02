<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class EditorAssetController extends Controller
{
    // Serves the vendored CodeMirror files (resources/vendor-js/codemirror/...) through a real
    // Laravel route instead of as plain static files under public/ - this host's static-file
    // serving has already proven unreliable for paths added straight to public/ (see
    // BlogContentAssetController's note on why storage:link isn't used either), so this follows
    // the same "stream it through the framework" pattern rather than depending on it.
    private const MIME_TYPES = [
        'js' => 'application/javascript',
        'css' => 'text/css',
    ];

    public function show(string $path): Response
    {
        // Guard against escaping the vendor-js root - normalize and reject any ".." segment.
        $normalized = str_replace('\\', '/', $path);

        if (str_contains($normalized, '..')) {
            abort(404);
        }

        $fullPath = resource_path('vendor-js/'.$normalized);

        if (! is_file($fullPath)) {
            abort(404);
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));

        return response(file_get_contents($fullPath), Response::HTTP_OK)
            ->header('Content-Type', self::MIME_TYPES[$extension] ?? 'application/octet-stream')
            ->header('Cache-Control', 'public, max-age=604800');
    }
}
