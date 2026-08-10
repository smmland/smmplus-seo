<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutoBlockAbusiveIpsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_block_synced_to_cpanel_gets_removed_there_too(): void
    {
        Http::fake(['*/add_ip*' => Http::response(['status' => 1], 200), '*/remove_ip*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = GatewayBlockedIp::query()->create([
            'ip' => '9.9.9.9',
            'is_active' => true,
            'blocked_until' => now()->subMinute(),
        ]);
        app(\App\Services\CpanelIpBlockerService::class)->block($record);
        $this->assertNotNull($record->fresh()->cpanel_synced_at);

        $this->artisan('gateway:auto-block-ips')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'remove_ip') && str_contains($request->url(), 'ip=9.9.9.9'));

        $record->refresh();
        $this->assertFalse($record->is_active);
        $this->assertNull($record->cpanel_synced_at);
    }

    public function test_an_expired_block_never_synced_to_cpanel_is_lifted_without_calling_cpanel(): void
    {
        Http::fake();

        GatewayBlockedIp::query()->create([
            'ip' => '9.9.9.9',
            'is_active' => true,
            'blocked_until' => now()->subMinute(),
        ]);

        $this->artisan('gateway:auto-block-ips')->assertSuccessful();

        Http::assertNothingSent();

        $this->assertFalse(GatewayBlockedIp::query()->where('ip', '9.9.9.9')->first()->is_active);
    }

    public function test_a_manual_block_with_no_expiry_is_left_alone(): void
    {
        Http::fake();

        GatewayBlockedIp::query()->create([
            'ip' => '9.9.9.9',
            'is_active' => true,
            'blocked_until' => null,
        ]);

        $this->artisan('gateway:auto-block-ips')->assertSuccessful();

        $this->assertTrue(GatewayBlockedIp::query()->where('ip', '9.9.9.9')->first()->is_active);
    }
}
