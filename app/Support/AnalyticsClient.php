<?php

namespace App\Support;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

class AnalyticsClient
{
    // Cloudflare's published proxy networks. CF-Connecting-IP and CF-IPCountry are trusted only
    // when the TCP peer belongs to one of these ranges; direct callers cannot bypass limits by
    // supplying lookalike forwarding headers.
    private const CLOUDFLARE_NETWORKS = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public static function resolveIp(Request $request): string
    {
        $peer = (string) ($request->server('REMOTE_ADDR') ?: $request->ip() ?: 'unknown');
        $forwarded = (string) $request->headers->get('CF-Connecting-IP', '');

        if (self::isCloudflarePeer($peer) && filter_var($forwarded, FILTER_VALIDATE_IP)) {
            return $forwarded;
        }

        return filter_var($peer, FILTER_VALIDATE_IP) ? $peer : 'unknown';
    }

    public static function countryCode(Request $request): ?string
    {
        $peer = (string) ($request->server('REMOTE_ADDR') ?: $request->ip() ?: '');
        if (! self::isCloudflarePeer($peer)) {
            return null;
        }

        $value = strtoupper((string) $request->headers->get('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $value) && ! in_array($value, ['XX', 'T1'], true)
            ? $value
            : null;
    }

    private static function isCloudflarePeer(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP)
            && IpUtils::checkIp($ip, self::CLOUDFLARE_NETWORKS);
    }
}
