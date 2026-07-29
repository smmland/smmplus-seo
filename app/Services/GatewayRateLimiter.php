<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Counts usage within a rolling window, keyed by an arbitrary string. Ported from the
 * original standalone script's Redis INCRBY-with-TTL helpers, but backed by Laravel's
 * cache (database driver) instead of the PHP redis extension, since this host doesn't
 * have it installed.
 */
class GatewayRateLimiter
{
    public function get(string $key): int
    {
        return (int) Cache::get($key, 0);
    }

    public function secondsRemaining(string $key): int
    {
        $expiresAt = Cache::get($key.':expires_at');

        return $expiresAt ? max(0, $expiresAt - now()->timestamp) : 0;
    }

    public function incrementWithTtl(string $key, int $amount, int $ttlSeconds): int
    {
        if (! Cache::has($key)) {
            Cache::put($key, 0, $ttlSeconds);
            Cache::put($key.':expires_at', now()->timestamp + $ttlSeconds, $ttlSeconds);
        }

        return Cache::increment($key, $amount);
    }
}
