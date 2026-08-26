<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AnalyticsTrackerController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse
    {
        // Keep the previously pinned build available during deployment. This lets the old layout
        // continue passing SRI after Core updates, until the website switches to v1.30.0.
        $file = $request->query('v') === '1.29.0' ? 'tracker-1.29.0.js' : 'tracker.js';
        $path = public_path('analytics/'.$file);

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
