<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Free, no-API-key IP-to-country lookup (ip-api.com) for the review-submission auto-detection
 * flow - this app has no GeoIP database or paid geolocation service, so it's a best-effort HTTP
 * call. Always fails soft: a slow/unreachable service or a private/local IP just yields nulls
 * rather than blocking a review from being submitted.
 */
class IpGeolocationService
{
    private const TIMEOUT_SECONDS = 5;

    /**
     * @return array{countryCode: ?string, countryName: ?string}
     */
    public function lookup(string $ip): array
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return ['countryCode' => null, 'countryName' => null];
        }

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)->get("http://ip-api.com/json/{$ip}", [
                'fields' => 'status,countryCode,country',
            ]);
        } catch (ConnectionException $e) {
            Log::warning('IP geolocation lookup failed to connect', ['ip' => $ip, 'error' => $e->getMessage()]);

            return ['countryCode' => null, 'countryName' => null];
        }

        if (! $response->successful() || $response->json('status') !== 'success') {
            return ['countryCode' => null, 'countryName' => null];
        }

        return [
            'countryCode' => $response->json('countryCode'),
            'countryName' => $response->json('country'),
        ];
    }
}
