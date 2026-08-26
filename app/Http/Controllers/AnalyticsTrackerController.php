<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnalyticsTrackerController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $path = public_path('analytics/tracker.js');

        abort_unless(is_file($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
            // The file is intentionally public. CORS is required for browsers to verify the
            // cross-origin Subresource Integrity hash used by the smm.plus layout.
            'Access-Control-Allow-Origin' => '*',
            'Cross-Origin-Resource-Policy' => 'cross-origin',
        ]);
    }
}
