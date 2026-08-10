<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayRateFloodTest extends TestCase
{
    use RefreshDatabase;

    private function order(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/free-service/order', [
            'service' => 'nonexistent-service',
            'link' => 'https://instagram.com/someuser',
        ], ['Origin' => 'https://smm.plus']);
    }

    public function test_ip_is_blocked_after_exceeding_requests_per_minute_when_auto_block_is_enabled(): void
    {
        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);

        // Threshold is "more than 3 per minute" - the first 3 should fall through normally
        // (rejected by the controller for an unknown service, not by the flood guard).
        for ($i = 0; $i < 3; $i++) {
            $response = $this->order();
            $response->assertStatus(400);
            $response->assertJsonFragment(['error' => 'Invalid service name']);
        }

        $this->assertFalse(GatewayBlockedIp::isBlocked('127.0.0.1'));

        // The 4th request in the same minute trips the flood guard.
        $response = $this->order();
        $response->assertStatus(429);

        $this->assertTrue(GatewayBlockedIp::isBlocked('127.0.0.1'));

        $log = GatewayRequestLog::query()->latest('id')->first();
        $this->assertSame(GatewayRequestLog::STATUS_RATE_FLOOD, $log->status);
    }

    public function test_flood_guard_does_not_reject_or_block_when_auto_block_is_disabled(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $response = $this->order();
            $response->assertStatus(400);
            $response->assertJsonFragment(['error' => 'Invalid service name']);
        }

        $this->assertFalse(GatewayBlockedIp::isBlocked('127.0.0.1'));
    }
}
