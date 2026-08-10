<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncBlockedIpsToHtaccessCommandTest extends TestCase
{
    use RefreshDatabase;

    private function configureCpanel(): void
    {
        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );
    }

    public function test_is_a_no_op_when_the_toggle_is_off(): void
    {
        $this->configureCpanel();
        GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => true]);

        Http::fake();

        $this->artisan('gateway:sync-blocked-ips-to-htaccess')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_adds_active_blocked_ips_missing_from_the_htaccess(): void
    {
        $this->configureCpanel();
        app(GatewaySettingsService::class)->setAutoSyncBlockedIpsEnabled(true);

        $record = GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => true]);

        Http::fake([
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => '']], 200),
            '*save_file_content*' => Http::response(['status' => 1], 200),
        ]);

        $this->artisan('gateway:sync-blocked-ips-to-htaccess')->assertSuccessful();

        Http::assertSent(fn ($request) => ! str_contains($request->url(), 'save_file_content')
            || $request['content'] === "deny from 1.2.3.4\n");

        $this->assertNotNull($record->fresh()->cpanel_synced_at);
    }

    public function test_ignores_inactive_and_expired_blocks(): void
    {
        $this->configureCpanel();
        app(GatewaySettingsService::class)->setAutoSyncBlockedIpsEnabled(true);

        GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => false]);
        GatewayBlockedIp::create(['ip' => '5.6.7.8', 'is_active' => true, 'blocked_until' => now()->subMinute()]);

        Http::fake(['*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => '']], 200)]);

        $this->artisan('gateway:sync-blocked-ips-to-htaccess')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_does_nothing_when_everything_is_already_present(): void
    {
        $this->configureCpanel();
        app(GatewaySettingsService::class)->setAutoSyncBlockedIpsEnabled(true);

        $record = GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => true, 'cpanel_synced_at' => now()]);

        Http::fake(['*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => "deny from 1.2.3.4\n"]], 200)]);

        $this->artisan('gateway:sync-blocked-ips-to-htaccess')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'save_file_content'));
    }

    public function test_does_not_throw_when_no_ips_are_blocked(): void
    {
        $this->configureCpanel();
        app(GatewaySettingsService::class)->setAutoSyncBlockedIpsEnabled(true);

        Http::fake();

        $this->artisan('gateway:sync-blocked-ips-to-htaccess')->assertSuccessful();

        Http::assertNothingSent();
    }
}
