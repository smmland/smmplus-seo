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

    private function record(string $ip = '1.2.3.4'): GatewayBlockedIp
    {
        return GatewayBlockedIp::query()->create(['ip' => $ip, 'is_active' => true, 'offense_count' => 1]);
    }

    public function test_does_not_call_cpanel_when_disabled(): void
    {
        Http::fake();

        app(CpanelIpBlockerService::class)->block($this->record());

        Http::assertNothingSent();
    }

    public function test_does_not_call_cpanel_when_enabled_but_not_fully_configured(): void
    {
        Http::fake();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(true, null, null, null);

        $record = $this->record();
        app(CpanelIpBlockerService::class)->block($record);

        Http::assertNothingSent();
        $this->assertNull($record->fresh()->cpanel_synced_at);
        $this->assertNotNull($record->fresh()->cpanel_sync_error);
    }

    public function test_calls_the_blockip_uapi_endpoint_when_fully_configured(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = $this->record();
        app(CpanelIpBlockerService::class)->block($record);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://server1.example.com:2083/execute/BlockIP/add_ip?ip=1.2.3.4'
                && $request->header('Authorization')[0] === 'cpanel someuser:secret-token';
        });

        $record->refresh();
        $this->assertNotNull($record->cpanel_synced_at);
        $this->assertNull($record->cpanel_sync_error);
    }

    public function test_a_failed_cpanel_call_records_the_error_but_does_not_throw_or_prevent_the_local_block(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['bad token']], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $settings = app(GatewaySettingsService::class);
        $record = GatewayBlockedIp::blockWithEscalation('1.2.3.4', 'test', $settings);

        $this->assertTrue($record->is_active);
        $this->assertTrue(GatewayBlockedIp::isBlocked('1.2.3.4'));

        $record->refresh();
        $this->assertNull($record->cpanel_synced_at);
        $this->assertStringContainsString('bad token', $record->cpanel_sync_error);
    }

    public function test_blocking_via_escalation_triggers_the_cpanel_call_and_records_success(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        Setting::query()->create(['key' => 'gateway_auto_block_enabled', 'value' => '1']);
        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = GatewayBlockedIp::blockWithEscalation('5.6.7.8', 'test reason', app(GatewaySettingsService::class));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'ip=5.6.7.8'));
        $this->assertNotNull($record->fresh()->cpanel_synced_at);
    }

    public function test_unblock_calls_remove_ip_and_clears_the_synced_timestamp(): void
    {
        Http::fake(['*/add_ip*' => Http::response(['status' => 1], 200), '*/remove_ip*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = $this->record();
        app(CpanelIpBlockerService::class)->block($record);
        $this->assertNotNull($record->fresh()->cpanel_synced_at);

        app(CpanelIpBlockerService::class)->unblock($record);

        Http::assertSent(function ($request) {
            return $request->url() === 'https://server1.example.com:2083/execute/BlockIP/remove_ip?ip=1.2.3.4';
        });
        $this->assertNull($record->fresh()->cpanel_synced_at);
    }

    public function test_unblock_is_a_no_op_when_the_record_was_never_synced(): void
    {
        Http::fake();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        app(CpanelIpBlockerService::class)->unblock($this->record());

        Http::assertNothingSent();
    }

    public function test_unblock_does_not_throw_when_disabled(): void
    {
        Http::fake();

        app(CpanelIpBlockerService::class)->unblock($this->record());

        Http::assertNothingSent();
    }

    public function test_block_many_sends_every_record_concurrently_and_marks_each_synced(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $records = collect(range(1, 5))->map(fn ($i) => $this->record("10.0.0.{$i}"));

        app(CpanelIpBlockerService::class)->blockMany($records);

        Http::assertSentCount(5);

        foreach ($records as $record) {
            $this->assertNotNull($record->fresh()->cpanel_synced_at);
            $this->assertNull($record->fresh()->cpanel_sync_error);
        }
    }

    public function test_block_many_records_a_per_record_failure_without_affecting_the_rest_of_the_batch(): void
    {
        Http::fake([
            '*ip=10.0.0.1*' => Http::response(['status' => 0, 'errors' => ['rejected']], 200),
            '*' => Http::response(['status' => 1], 200),
        ]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $failing = $this->record('10.0.0.1');
        $succeeding = $this->record('10.0.0.2');

        app(CpanelIpBlockerService::class)->blockMany(collect([$failing, $succeeding]));

        $failing->refresh();
        $succeeding->refresh();

        $this->assertNull($failing->cpanel_synced_at);
        $this->assertStringContainsString('rejected', $failing->cpanel_sync_error);

        $this->assertNotNull($succeeding->cpanel_synced_at);
        $this->assertNull($succeeding->cpanel_sync_error);
    }

    public function test_block_many_is_a_no_op_when_not_configured(): void
    {
        Http::fake();

        app(CpanelIpBlockerService::class)->blockMany(collect([$this->record()]));

        Http::assertNothingSent();
    }

    public function test_block_many_does_nothing_for_an_empty_collection(): void
    {
        Http::fake();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        app(CpanelIpBlockerService::class)->blockMany(collect());

        Http::assertNothingSent();
    }

    public function test_fetch_htaccess_block_list_parses_deny_from_lines(): void
    {
        $htaccess = <<<'HTACCESS'
            <Limit GET POST>
            order allow,deny
            deny from 1.2.3.4
            Deny from 5.6.7.8 9.10.11.12
            deny from all
            allow from all
            </Limit>
            HTACCESS;

        Http::fake(['*' => Http::response(['status' => 1, 'data' => ['content' => $htaccess]], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );

        $result = app(CpanelIpBlockerService::class)->fetchHtaccessBlockList();

        $this->assertTrue($result['ok']);
        $this->assertSame(['1.2.3.4', '5.6.7.8', '9.10.11.12'], $result['ips']);
        $this->assertNull($result['error']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/execute/Fileman/get_file_content')
            && $request['dir'] === 'public_html'
            && $request['file'] === '.htaccess');
    }

    public function test_fetch_htaccess_block_list_requires_a_configured_path(): void
    {
        Http::fake();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $result = app(CpanelIpBlockerService::class)->fetchHtaccessBlockList();

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['ips']);
        $this->assertNotNull($result['error']);
        Http::assertNothingSent();
    }

    public function test_fetch_htaccess_block_list_surfaces_a_cpanel_error(): void
    {
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['file not found']], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );

        $result = app(CpanelIpBlockerService::class)->fetchHtaccessBlockList();

        $this->assertFalse($result['ok']);
        $this->assertSame([], $result['ips']);
        $this->assertStringContainsString('file not found', $result['error']);
    }

    public function test_fetch_htaccess_block_list_is_a_no_op_when_cpanel_not_configured(): void
    {
        Http::fake();

        $result = app(CpanelIpBlockerService::class)->fetchHtaccessBlockList();

        $this->assertFalse($result['ok']);
        Http::assertNothingSent();
    }
}
