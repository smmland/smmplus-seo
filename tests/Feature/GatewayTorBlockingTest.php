<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Models\Setting;
use App\Services\TorExitNodeListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GatewayTorBlockingTest extends TestCase
{
    use RefreshDatabase;

    private function seedTorList(): void
    {
        $ips = collect(range(1, 150))->map(fn ($i) => "127.0.0.{$i}")->implode("\n");
        Http::fake(['*' => Http::response($ips, 200)]);
        app(TorExitNodeListService::class)->refresh();
    }

    private function order(): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/free-service/order', [
            'service' => 'nonexistent-service',
            'link' => 'https://instagram.com/someuser',
        ], ['Origin' => 'https://smm.plus']);
    }

    public function test_a_request_from_a_known_tor_exit_node_is_rejected_when_enabled(): void
    {
        $this->seedTorList();
        Setting::query()->create(['key' => 'gateway_tor_blocking_enabled', 'value' => '1']);

        // The test client's IP is 127.0.0.1, which is in the fake exit list above.
        $response = $this->order();

        $response->assertStatus(403);

        $log = GatewayRequestLog::query()->latest('id')->first();
        $this->assertSame(GatewayRequestLog::STATUS_TOR_EXIT_NODE, $log->status);
    }

    public function test_a_request_from_a_known_tor_exit_node_passes_through_when_disabled(): void
    {
        $this->seedTorList();

        $response = $this->order();

        $response->assertStatus(400);
        $response->assertJsonFragment(['error' => 'Invalid service name']);
    }

    public function test_tor_blocking_does_not_create_a_gateway_blocked_ip_record_when_auto_block_is_off(): void
    {
        $this->seedTorList();
        Setting::query()->create(['key' => 'gateway_tor_blocking_enabled', 'value' => '1']);

        $this->order();

        $this->assertSame(0, GatewayBlockedIp::query()->count());
    }

    public function test_a_tor_hit_registers_a_flat_duration_block_when_auto_block_is_on(): void
    {
        $this->seedTorList();
        Setting::query()->create(['key' => 'gateway_tor_blocking_enabled', 'value' => '1']);
        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);
        Setting::query()->create(['key' => 'gateway_tor_block_days', 'value' => '14']);

        $this->order();

        $record = GatewayBlockedIp::query()->where('ip', '127.0.0.1')->first();
        $this->assertNotNull($record);
        $this->assertTrue($record->is_active);
        $this->assertSame(0, $record->offense_count);
        $this->assertTrue($record->blocked_until->between(now()->addDays(13), now()->addDays(15)));

        // A second hit from the same IP should now be rejected by the fast isBlocked() path,
        // not re-evaluated against the Tor list.
        $response = $this->order();
        $response->assertStatus(403);
        $log = GatewayRequestLog::query()->latest('id')->first();
        $this->assertSame(GatewayRequestLog::STATUS_BLOCKED_IP, $log->status);
    }
}
