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

    /**
     * @return array<int, string>
     */
    public function all(): array
    {
        return array_keys(Cache::get(self::CACHE_KEY, []));
    }

    public function lastRefreshedAt(): ?Carbon
    {
        // Stored as a plain string, not a Carbon instance - the database cache store
        // round-trips scalars fine, but an object can come back as __PHP_Incomplete_Class
        // depending on autoload timing, which crashed this exact read in production. Guarded
        // so a leftover value cached by that older, buggy version (or any other unexpected
        // shape) degrades to "not fetched yet" instead of erroring again.
        $stored = Cache::get(self::CACHE_UPDATED_KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return Carbon::parse($stored);
        } catch (\Throwable) {
            return null;
        }
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
            Cache::forever(self::CACHE_UPDATED_KEY, now()->toIso8601String());

            return count($ips);
        } catch (\Throwable $e) {
            Log::warning('Tor exit node list: refresh failed', ['error' => $e->getMessage()]);

            return $this->count();
        }
    }
}
