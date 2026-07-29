<?php

namespace App\Http\Middleware;

use App\Services\GatewaySettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleGatewayCors
{
    public function __construct(private readonly GatewaySettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');
        $isAllowedOrigin = in_array($origin, $this->settings->getAllowedOrigins(), true);

        if ($request->getMethod() === 'OPTIONS') {
            $response = response('', 204);
        } else {
            $response = $next($request);
        }

        if ($isAllowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
