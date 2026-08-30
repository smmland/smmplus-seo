<?php

namespace App\Http\Middleware;

use App\Services\GatewayRateLimiter;
use App\Services\GatewaySettingsService;
use App\Support\AnalyticsClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAnalyticsCors
{
    private const MAX_PAYLOAD_BYTES = 32768;

    private const MAX_REQUESTS_PER_MINUTE = 120;

    private const MAX_EVENTS_PER_HOUR = 10000;

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
            return $this->withSecurityHeaders(response()->json(['ok' => false, 'error' => 'Origin not allowed'], 403));
        }

        $contentType = strtolower((string) $request->headers->get('Content-Type'));
        if (! str_starts_with($contentType, 'text/plain') && ! str_starts_with($contentType, 'application/json')) {
            return $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Unsupported content type'], 415),
                $origin,
                true,
            );
        }

        if (strlen($request->getContent()) > self::MAX_PAYLOAD_BYTES) {
            return $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Payload too large'], 413),
                $origin,
                true,
            );
        }

        $ip = AnalyticsClient::resolveIp($request);
        $rateKey = 'analytics:rate:'.hash('sha256', $ip);
        if ($this->limiter->incrementWithTtl($rateKey, 1, 60) > self::MAX_REQUESTS_PER_MINUTE) {
            $response = $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Too many requests'], 429),
                $origin,
                true,
            );
            $response->headers->set('Retry-After', '60');

            return $response;
        }

        $decoded = json_decode($request->getContent(), true);
        $eventCount = is_array($decoded['events'] ?? null) ? count($decoded['events']) : 1;
        $eventRateKey = 'analytics:events:'.hash('sha256', $ip);
        if ($this->limiter->incrementWithTtl($eventRateKey, max(1, $eventCount), 3600) > self::MAX_EVENTS_PER_HOUR) {
            $response = $this->withCorsHeaders(
                response()->json(['ok' => false, 'error' => 'Hourly event limit exceeded'], 429),
                $origin,
                true,
            );
            $response->headers->set('Retry-After', '3600');

            return $response;
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

        return $this->withSecurityHeaders($response);
    }

    private function withSecurityHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        return $response;
    }
}
