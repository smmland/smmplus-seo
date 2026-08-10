<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatewayUnreasonableInputTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_link_is_rejected_without_reaching_the_controller(): void
    {
        $response = $this->postJson('/api/free-service/order', [
            'service' => 'some-service',
            'link' => str_repeat('@', 5000),
        ], ['Origin' => 'https://smm.plus']);

        $response->assertStatus(400);

        $log = GatewayRequestLog::query()->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame(GatewayRequestLog::STATUS_UNREASONABLE_INPUT, $log->status);
    }

    public function test_oversized_link_instantly_blocks_the_ip_when_auto_block_is_enabled(): void
    {
        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);

        $this->postJson('/api/free-service/order', [
            'service' => 'some-service',
            'link' => str_repeat('@', 5000),
        ], ['Origin' => 'https://smm.plus']);

        $this->assertTrue(GatewayBlockedIp::isBlocked('127.0.0.1'));

        $blocked = GatewayBlockedIp::query()->where('ip', '127.0.0.1')->first();
        $this->assertSame(1, $blocked->offense_count);
    }

    public function test_oversized_link_does_not_block_the_ip_when_auto_block_is_disabled(): void
    {
        $this->postJson('/api/free-service/order', [
            'service' => 'some-service',
            'link' => str_repeat('@', 5000),
        ], ['Origin' => 'https://smm.plus']);

        $this->assertFalse(GatewayBlockedIp::isBlocked('127.0.0.1'));
    }

    public function test_normal_length_link_is_not_rejected_as_unreasonable(): void
    {
        $response = $this->postJson('/api/free-service/order', [
            'service' => 'nonexistent-service',
            'link' => 'https://instagram.com/someuser',
        ], ['Origin' => 'https://smm.plus']);

        // Falls through to the controller, which rejects it for being an unknown service -
        // proving the length check itself did not trip.
        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Invalid service name']);
    }

    public function test_repeated_oversized_requests_escalate_the_block_duration(): void
    {
        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);

        $this->postJson('/api/free-service/order', [
            'service' => 'some-service',
            'link' => str_repeat('@', 5000),
        ], ['Origin' => 'https://smm.plus']);

        $first = GatewayBlockedIp::query()->where('ip', '127.0.0.1')->first();
        $first->update(['is_active' => false]);

        $this->postJson('/api/free-service/order', [
            'service' => 'some-service',
            'link' => str_repeat('@', 5000),
        ], ['Origin' => 'https://smm.plus']);

        $second = GatewayBlockedIp::query()->where('ip', '127.0.0.1')->first();
        $this->assertSame(2, $second->offense_count);
        $this->assertTrue($second->blocked_until->greaterThan($first->blocked_until));
    }
}
