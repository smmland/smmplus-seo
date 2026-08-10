<?php

namespace App\Http\Middleware;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Services\GatewayRateLimiter;
use App\Services\GatewaySettingsService;
use App\Services\TorExitNodeListService;
use App\Support\GatewayClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleGatewayCors
{
    // A real link/service value from any legitimate caller is never remotely this long
    // (typical social URLs are well under 100 chars) - this only exists to catch garbage
    // padding (e.g. thousands of repeated characters) seen in the wild from abusive callers.
    private const MAX_REASONABLE_INPUT_LENGTH = 300;

    // A real browser user never fires more than a couple of these in a minute - anything
    // past this is a script hammering the endpoint (confirmed against a live flood while
    // diagnosing 503s from the host's entry-process limit being exhausted).
    private const MAX_REQUESTS_PER_MINUTE = 3;

    public function __construct(
        private readonly GatewaySettingsService $settings,
        private readonly GatewayRateLimiter $limiter,
        private readonly TorExitNodeListService $torExitNodes,
    ) {}

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

        if ($this->settings->isTorBlockingEnabled() && $this->torExitNodes->isExitNode($ip)) {
            // Not registered in GatewayBlockedIp/cPanel like the other triggers below - Tor exit
            // IPs are a large, constantly rotating pool (a different node reused by a different
            // person days later), so blocking this one specific IP going forward would be both
            // pointless and would bloat that table. This check re-evaluates fresh on every
            // request against the current exit-node list instead.
            $this->log($ip, $origin, GatewayRequestLog::STATUS_TOR_EXIT_NODE);

            return response()->json(['ok' => false, 'error' => 'Requests via Tor are not allowed.'], 403);
        }

        if ($this->settings->isAutoBlockEnabled() && $this->isFlooding($ip)) {
            $this->log($ip, $origin, GatewayRequestLog::STATUS_RATE_FLOOD);

            GatewayBlockedIp::blockWithEscalation($ip, 'Auto-blocked: more than '.self::MAX_REQUESTS_PER_MINUTE.' requests/minute', $this->settings);

            return response()->json(['ok' => false, 'error' => 'Too many requests.'], 429);
        }

        if ($this->hasUnreasonableInput($request)) {
            $this->log($ip, $origin, GatewayRequestLog::STATUS_UNREASONABLE_INPUT);

            // A single grossly-oversized payload is already a near-zero-false-positive abuse
            // signal on its own, unlike the volume threshold (which needs patience to avoid
            // flagging bursts of legitimate use) - block immediately rather than waiting for
            // the scheduled sweep. Still respects the admin's auto-block on/off preference.
            if ($this->settings->isAutoBlockEnabled()) {
                GatewayBlockedIp::blockWithEscalation($ip, 'Auto-blocked: oversized request payload', $this->settings);
            }

            return response()->json(['ok' => false, 'error' => 'Invalid request.'], 400);
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

    private function isFlooding(string $ip): bool
    {
        $key = 'gateway:rate:minute:'.md5($ip);

        return $this->limiter->incrementWithTtl($key, 1, 60) > self::MAX_REQUESTS_PER_MINUTE;
    }

    private function hasUnreasonableInput(Request $request): bool
    {
        foreach (['link', 'service'] as $field) {
            $value = $request->input($field);

            if (is_string($value) && mb_strlen($value) > self::MAX_REASONABLE_INPUT_LENGTH) {
                return true;
            }
        }

        return false;
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
