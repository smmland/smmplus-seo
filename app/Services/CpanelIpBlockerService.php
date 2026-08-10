<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Registers a blocked IP with cPanel's own IP Blocker (UAPI module BlockIP, function add_ip),
// so abusive traffic gets rejected by Apache/LiteSpeed itself instead of ever reaching PHP -
// the actual fix for the entry-process-exhaustion 503s a flood causes, since our own
// GatewayBlockedIp check still has to spin up a PHP process to reject the request.
// Optional and off by default: needs a cPanel API token the account owner generates
// themselves (Security > Manage API Tokens - no root/WHM access required).
class CpanelIpBlockerService
{
    public function __construct(private readonly GatewaySettingsService $settings) {}

    public function block(string $ip): void
    {
        if (! $this->settings->isCpanelBlockerEnabled()) {
            return;
        }

        $host = $this->settings->getCpanelHost();
        $username = $this->settings->getCpanelUsername();
        $token = $this->settings->getCpanelApiToken();

        if (! $host || ! $username || ! $token) {
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "cpanel {$username}:{$token}",
            ])
                ->timeout(5)
                ->get("https://{$host}/execute/BlockIP/add_ip", ['ip' => $ip]);

            if (! $response->successful() || ($response->json('status') === 0)) {
                Log::warning('cPanel IP Blocker: failed to block IP', [
                    'ip' => $ip,
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            // Never let a cPanel API hiccup break the block flow - our own GatewayBlockedIp
            // record above is already saved regardless of whether this call succeeds.
            Log::warning('cPanel IP Blocker: request failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
