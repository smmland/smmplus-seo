<?php

namespace App\Http\Middleware;

use App\Services\GatewayRateLimiter;
use App\Services\GatewaySettingsService;
use App\Support\GatewayClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAnalyticsCors
{
    private const MAX_PAYLOAD_BYTES = 65536;

    private const MAX_REQUESTS_PER_MINUTE = 120;

    public function __construct(
        private readonly GatewaySettingsService $settings,
        private readonly GatewayRateLimiter $limiter,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $origin = $request->headers->get('Origin', '');
        $isAllowedOrigin = $origin !== '' && in_array($origin, $this->settings->getAllowedOrigins(), true);

        if ($request->isMethod('OPTIONS')) {
            return $this->withCorsHeaders(response('', $isAllowedOrigin ? 204 : 403), $origin, $isAllowedOrigin);
        }

        if (! $isAllowedOrigin) {
            return response()->json(['ok' => false, 'error' => 'Origin not allowed'], 403);
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Payload too large'], 413),
                $origin,
                true,
            );
        }

        $ip = GatewayClient::resolveIp($request);
        $rateKey = 'analytics:rate:'.hash('sha256', $ip);
        if ($this->limiter->incrementWithTtl($rateKey, 1, 60) > self::MAX_REQUESTS_PER_MINUTE) {
            return $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Too many requests'], 429),
                $origin,
                true,
            );
        }

        return $this->withCorsHeaders($next($request), $origin, true);
    }

    private function withCorsHeaders(Response $response, string $origin, bool $allowed): Response
    {
        if ($allowed) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'POST, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type');
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }
}
