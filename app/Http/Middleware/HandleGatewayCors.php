<?php

namespace App\Http\Middleware;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Services\GatewaySettingsService;
use App\Support\GatewayClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleGatewayCors
{
    public function __construct(private readonly GatewaySettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');
        $isAllowedOrigin = $origin !== '' && in_array($origin, $this->settings->getAllowedOrigins(), true);

        if ($request->getMethod() === 'OPTIONS') {
            return $this->withCorsHeaders(response('', 204), $origin, $isAllowedOrigin);
        }

        $ip = GatewayClient::resolveIp($request);

        if (GatewayBlockedIp::isBlocked($ip)) {
            $this->log($ip, $origin, GatewayRequestLog::STATUS_BLOCKED_IP);

            return response()->json(['ok' => false, 'error' => 'Your IP has been blocked from using this service.'], 403);
        }

        if (! $isAllowedOrigin) {
            // CORS headers alone only stop a browser from *reading* a cross-origin response -
            // the request still reaches the server either way. Reject outright here so a
            // disallowed origin (or a non-browser caller with no Origin header at all) can't
            // trigger a real order or consume rate-limit quota in the first place.
            $this->log($ip, $origin, GatewayRequestLog::STATUS_INVALID_ORIGIN);

            return response()->json(['ok' => false, 'error' => 'Origin not allowed'], 403);
        }

        return $this->withCorsHeaders($next($request), $origin, $isAllowedOrigin);
    }

    private function withCorsHeaders(Response $response, string $origin, bool $isAllowedOrigin): Response
    {
        if ($isAllowedOrigin) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST, GET, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function log(string $ip, string $origin, string $status): void
    {
        GatewayRequestLog::create([
            'ip' => $ip,
            'origin' => $origin ?: null,
            'status' => $status,
        ]);
    }
}
