<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Maintains a cached copy of the Tor Project's official exit-node IP list, so gateway abuse
// routed through Tor (a different exit IP every few requests) can be blocked as a whole
// category instead of chasing one IP at a time - a per-IP block is already stale by the time
// it's applied against that kind of traffic. Refreshed hourly by a scheduled command; the
// gateway middleware only ever reads the cache, never fetches live, so a slow/failed refresh
// can never add latency or a failure mode to a real request.
class TorExitNodeListService
{
    private const CACHE_KEY = 'tor_exit_nodes';
    private const CACHE_UPDATED_KEY = 'tor_exit_nodes_updated_at';
    private const SOURCE_URL = 'https://check.torproject.org/torbulkexitlist';

    // A genuine fetch is always in the thousands - anything smaller almost certainly means a
    // bad/empty response, and refreshing the cache with it would silently disable this check.
    private const MIN_PLAUSIBLE_COUNT = 100;

    public function isExitNode(string $ip): bool
    {
        return isset(Cache::get(self::CACHE_KEY, [])[$ip]);
    }

    public function count(): int
    {
        return count(Cache::get(self::CACHE_KEY, []));
    }

    public function lastRefreshedAt(): ?Carbon
    {
        return Cache::get(self::CACHE_UPDATED_KEY);
    }

    public function refresh(): int
    {
        try {
            $response = Http::timeout(15)->get(self::SOURCE_URL);

            if (! $response->successful()) {
                Log::warning('Tor exit node list: fetch failed', ['http_status' => $response->status()]);

                return $this->count();
            }

            $ips = collect(explode("\n", $response->body()))
                ->map(fn (string $line) => trim($line))
                ->filter(fn (string $line) => $line !== '' && ! str_starts_with($line, '#'))
                ->mapWithKeys(fn (string $ip) => [$ip => true])
                ->all();

            if (count($ips) < self::MIN_PLAUSIBLE_COUNT) {
                Log::warning('Tor exit node list: fetched list looked too small, keeping previous', ['count' => count($ips)]);

                return $this->count();
            }

            Cache::forever(self::CACHE_KEY, $ips);
            Cache::forever(self::CACHE_UPDATED_KEY, now());

            return count($ips);
        } catch (\Throwable $e) {
            Log::warning('Tor exit node list: refresh failed', ['error' => $e->getMessage()]);

            return $this->count();
        }
    }
}
