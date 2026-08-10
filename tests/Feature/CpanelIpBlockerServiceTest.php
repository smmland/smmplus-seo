<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Models\Setting;
use App\Services\CpanelIpBlockerService;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CpanelIpBlockerServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_does_not_call_cpanel_when_disabled(): void
    {
        Http::fake();

        app(CpanelIpBlockerService::class)->block('1.2.3.4');

        Http::assertNothingSent();
    }

    public function test_does_not_call_cpanel_when_enabled_but_not_fully_configured(): void
    {
        Http::fake();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(true, null, null, null);

        app(CpanelIpBlockerService::class)->block('1.2.3.4');

        Http::assertNothingSent();
    }

    public function test_calls_the_blockip_uapi_endpoint_when_fully_configured(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        app(CpanelIpBlockerService::class)->block('1.2.3.4');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://server1.example.com:2083/execute/BlockIP/add_ip?ip=1.2.3.4'
                && $request->header('Authorization')[0] === 'cpanel someuser:secret-token';
        });
    }

    public function test_a_failed_cpanel_call_does_not_throw_and_does_not_prevent_the_local_block(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['bad token']], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $settings = app(GatewaySettingsService::class);
        $record = GatewayBlockedIp::blockWithEscalation('1.2.3.4', 'test', $settings);

        $this->assertTrue($record->is_active);
        $this->assertTrue(GatewayBlockedIp::isBlocked('1.2.3.4'));
    }

    public function test_blocking_via_escalation_triggers_the_cpanel_call_when_configured(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);
        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        GatewayBlockedIp::blockWithEscalation('5.6.7.8', 'test reason', app(GatewaySettingsService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ip=5.6.7.8'));
    }
}
