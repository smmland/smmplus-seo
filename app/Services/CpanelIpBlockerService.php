<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use App\Models\GatewayBlockedIp;
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
        } catch (\Throwable $e) {
            // Never let a cPanel API hiccup break the block flow - our own GatewayBlockedIp
            // record is already saved regardless of whether this call succeeds.
            $record->update(['cpanel_sync_error' => $e->getMessage()]);

            Log::warning('cPanel IP Blocker: request failed', [
                'ip' => $record->ip,
                'error' => $e->getMessage(),
            ]);
        }
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

    private function client(): ?PendingRequest
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

        return Http::baseUrl("https://{$host}")
            ->withHeaders(['Authorization' => "cpanel {$username}:{$token}"])
            ->timeout(5);
    }
}
