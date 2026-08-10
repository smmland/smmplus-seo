<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use App\Models\GatewayBlockedIp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Registers a blocked IP with cPanel's own IP Blocker (UAPI module BlockIP, functions add_ip /
// remove_ip), so abusive traffic gets rejected by Apache/LiteSpeed itself instead of ever
// reaching PHP - the actual fix for the entry-process-exhaustion 503s a flood causes, since our
// own GatewayBlockedIp check still has to spin up a PHP process to reject the request.
// Optional and off by default: needs a cPanel API token the account owner generates
// themselves (Security > Manage API Tokens - no root/WHM access required).
class CpanelIpBlockerService
{
    public function __construct(private readonly GatewaySettingsService $settings) {}

    // Writes the outcome onto the record itself (cpanel_synced_at / cpanel_sync_error) so it
    // can be confirmed straight from the database - e.g. "SELECT ... ORDER BY updated_at DESC
    // LIMIT 50" - without needing log file access.
    public function block(GatewayBlockedIp $record): void
    {
        $client = $this->client();

        if (! $client) {
            if ($this->settings->isCpanelBlockerEnabled()) {
                $record->update(['cpanel_sync_error' => 'cPanel IP Blocker is enabled but host/username/token is not fully configured.']);
            }

            return;
        }

        try {
            $response = $client->get('/execute/BlockIP/add_ip', ['ip' => $record->ip]);

            $this->applyBlockResult($record, $response);
        } catch (\Throwable $e) {
            // Never let a cPanel API hiccup break the block flow - our own GatewayBlockedIp
            // record is already saved regardless of whether this call succeeds.
            $this->applyBlockResult($record, $e);
        }
    }

    /**
     * Registers several already-created GatewayBlockedIp records with cPanel at once via
     * concurrent outbound HTTP requests (Http::pool), instead of one at a time - the caller
     * controls the batch size by how many records it passes in (e.g.
     * gateway:sync-tor-bulk-block-to-cpanel sends 5 at a time). Same best-effort semantics as
     * block(): a failure is written onto that record (cpanel_sync_error) rather than thrown, and
     * never stops the rest of the batch - a per-request transport failure inside a pool comes
     * back as the exception itself rather than aborting the pool.
     *
     * @param  Collection<int, GatewayBlockedIp>  $records
     */
    public function blockMany(Collection $records): void
    {
        if ($records->isEmpty()) {
            return;
        }

        $credentials = $this->credentials();

        if (! $credentials) {
            if ($this->settings->isCpanelBlockerEnabled()) {
                $records->each(fn (GatewayBlockedIp $record) => $record->update([
                    'cpanel_sync_error' => 'cPanel IP Blocker is enabled but host/username/token is not fully configured.',
                ]));
            }

            return;
        }

        $responses = Http::pool(fn (Pool $pool) => $records->map(
            fn (GatewayBlockedIp $record) => $pool->as((string) $record->id)
                ->baseUrl("https://{$credentials['host']}")
                ->withHeaders(['Authorization' => "cpanel {$credentials['username']}:{$credentials['token']}"])
                ->timeout(5)
                ->get('/execute/BlockIP/add_ip', ['ip' => $record->ip])
        )->all());

        foreach ($records as $record) {
            $this->applyBlockResult($record, $responses[(string) $record->id] ?? null);
        }
    }

    private function applyBlockResult(GatewayBlockedIp $record, Response|\Throwable|null $response): void
    {
        if ($response instanceof Response) {
            if ($response->successful() && $response->json('status') !== 0) {
                $record->update(['cpanel_synced_at' => now(), 'cpanel_sync_error' => null]);

                return;
            }

            $reason = $response->json('errors.0') ?? $response->body();

            $record->update(['cpanel_sync_error' => "HTTP {$response->status()}: {$reason}"]);

            Log::warning('cPanel IP Blocker: failed to block IP', [
                'ip' => $record->ip,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $message = $response instanceof \Throwable ? $response->getMessage() : 'The request failed.';

        $record->update(['cpanel_sync_error' => $message]);

        Log::warning('cPanel IP Blocker: request failed', [
            'ip' => $record->ip,
            'error' => $message,
        ]);
    }

    // Called when a timed auto-block expires (AutoBlockAbusiveIpsCommand) - only makes sense
    // for a record this service previously synced (cpanel_synced_at set); a manual block an
    // admin created directly in cPanel, or one that never made it there, is left alone.
    public function unblock(GatewayBlockedIp $record): void
    {
        $client = $this->client();

        if (! $client || $record->cpanel_synced_at === null) {
            return;
        }

        try {
            $response = $client->get('/execute/BlockIP/remove_ip', ['ip' => $record->ip]);

            if ($response->successful() && $response->json('status') !== 0) {
                $record->update(['cpanel_synced_at' => null]);

                return;
            }

            Log::warning('cPanel IP Blocker: failed to unblock IP', [
                'ip' => $record->ip,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('cPanel IP Blocker: unblock request failed', [
                'ip' => $record->ip,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Reads and parses the actual "deny from" list cPanel's IP Blocker writes to the account's
     * .htaccess - there's no UAPI function to list what BlockIP has blocked (only add_ip /
     * remove_ip exist), so this is the only way to see it. Uses the Fileman UAPI module (a
     * different cPanel feature entirely) to read the configured file's raw content, which is
     * inherently more fragile than a real listing endpoint - it breaks if the path is wrong or
     * cPanel ever changes the format it writes, so every failure mode is surfaced as a message
     * rather than assumed to be an empty list.
     *
     * @return array{ok: bool, ips: array<int, string>, error: ?string}
     */
    public function fetchHtaccessBlockList(): array
    {
        $client = $this->client();
        $path = $this->settings->getCpanelHtaccessPath();

        if (! $client) {
            return ['ok' => false, 'ips' => [], 'error' => 'cPanel IP Blocker is not fully configured (Security Settings).'];
        }

        if (! $path) {
            return ['ok' => false, 'ips' => [], 'error' => 'No .htaccess path configured (Security Settings).'];
        }

        $dir = dirname($path);
        $file = basename($path);

        try {
            $response = $client->get('/execute/Fileman/get_file_content', [
                'dir' => $dir === '.' ? '/' : $dir,
                'file' => $file,
                'to_charset' => 'utf-8',
            ]);

            if (! $response->successful() || $response->json('status') !== 1) {
                $reason = $response->json('errors.0') ?? $response->body();

                return ['ok' => false, 'ips' => [], 'error' => "HTTP {$response->status()}: {$reason}"];
            }

            return ['ok' => true, 'ips' => $this->parseDeniedIps((string) $response->json('data.content')), 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('cPanel IP Blocker: fetching .htaccess block list failed', ['error' => $e->getMessage()]);

            return ['ok' => false, 'ips' => [], 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array<int, string>
     */
    private function parseDeniedIps(string $content): array
    {
        $ips = [];

        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            if (! preg_match('/^\s*deny\s+from\s+(.+?)\s*$/i', $line, $matches)) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($matches[1])) as $token) {
                if ($token !== '' && strtolower($token) !== 'all') {
                    $ips[] = $token;
                }
            }
        }

        return array_values(array_unique($ips));
    }

    private function client(): ?PendingRequest
    {
        $credentials = $this->credentials();

        if (! $credentials) {
            return null;
        }

        return Http::baseUrl("https://{$credentials['host']}")
            ->withHeaders(['Authorization' => "cpanel {$credentials['username']}:{$credentials['token']}"])
            ->timeout(5);
    }

    /**
     * @return array{host: string, username: string, token: string}|null
     */
    private function credentials(): ?array
    {
        if (! $this->settings->isCpanelBlockerEnabled()) {
            return null;
        }

        $host = $this->settings->getCpanelHost();
        $username = $this->settings->getCpanelUsername();
        $token = $this->settings->getCpanelApiToken();

        if (! $host || ! $username || ! $token) {
            return null;
        }

        return ['host' => $host, 'username' => $username, 'token' => $token];
    }
}
