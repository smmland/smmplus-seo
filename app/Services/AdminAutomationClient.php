<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talks to the Node/Playwright companion service (see automation/README.md) that actually drives
 * a browser against the admin panel - a real login flow with JS/hCaptcha can't be done from PHP.
 */
class AdminAutomationClient
{
    public function __construct(private AdminAutomationSettingsService $settings)
    {
    }

    public function startLogin(): array
    {
        $username = $this->settings->getUsername();
        $password = $this->settings->getPassword();

        if (! $username || ! $password) {
            throw new RuntimeException('Admin panel username/password are not configured yet.');
        }

        return $this->request()
            ->post('/sessions', [
                'panelUrl' => $this->settings->getPanelUrl(),
                'username' => $username,
                'password' => $password,
            ])
            ->throw()
            ->json();
    }

    public function getStatus(string $sessionId): array
    {
        return $this->request()->get("/sessions/{$sessionId}")->throw()->json();
    }

    public function getFrame(string $sessionId): ?string
    {
        $response = $this->request()->get("/sessions/{$sessionId}/frame.jpg");

        if ($response->status() === 204) {
            return null;
        }

        return $response->throw()->body();
    }

    public function forwardInput(string $sessionId, array $event): array
    {
        return $this->request()->post("/sessions/{$sessionId}/input", $event)->throw()->json();
    }

    public function cancel(string $sessionId): void
    {
        $this->request()->delete("/sessions/{$sessionId}");
    }

    private function request()
    {
        $baseUrl = $this->settings->getServiceUrl();
        $token = $this->settings->getServiceToken();

        if (! $baseUrl || ! $token) {
            throw new RuntimeException('The automation service URL/token are not configured yet.');
        }

        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->timeout(15);
    }
}
