<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncTorBulkBlockToCpanelCommandTest extends TestCase
{
    use RefreshDatabase;

    private function queueBulkBlockedIp(string $ip): GatewayBlockedIp
    {
        return GatewayBlockedIp::create([
            'ip' => $ip,
            'note' => 'Tor exit-node bulk block (expires in 7d)',
            'is_active' => true,
            'blocked_until' => now()->addDays(7),
        ]);
    }

    public function test_syncs_queued_bulk_blocks_to_cpanel_five_at_a_time(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $records = collect(range(1, 12))->map(fn ($i) => $this->queueBulkBlockedIp("10.0.0.{$i}"));

        $this->artisan('gateway:sync-tor-bulk-block-to-cpanel')->assertSuccessful();

        Http::assertSentCount(12);

        foreach ($records as $record) {
            $this->assertNotNull($record->fresh()->cpanel_synced_at);
        }
    }

    public function test_ignores_records_that_are_not_tagged_as_a_bulk_tor_block(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $reactive = GatewayBlockedIp::create([
            'ip' => '10.0.0.1',
            'note' => 'Tor exit node (expires in 7d)',
            'is_active' => true,
            'blocked_until' => now()->addDays(7),
        ]);
        $manual = GatewayBlockedIp::create(['ip' => '10.0.0.2', 'note' => 'Manual block', 'is_active' => true]);

        $this->artisan('gateway:sync-tor-bulk-block-to-cpanel')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertNull($reactive->fresh()->cpanel_synced_at);
        $this->assertNull($manual->fresh()->cpanel_synced_at);
    }

    public function test_skips_records_that_are_already_synced(): void
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = $this->queueBulkBlockedIp('10.0.0.1');
        $record->update(['cpanel_synced_at' => now()]);

        $this->artisan('gateway:sync-tor-bulk-block-to-cpanel')->assertSuccessful();

        Http::assertNothingSent();
    }

    public function test_a_persistent_failure_does_not_spin_the_same_batch_for_the_whole_run(): void
    {
        // Every request fails - if the failed batch got reselected every loop iteration, this
        // would hammer the fake endpoint dozens of times within the run's own time budget
        // instead of moving on and waiting for the next scheduled tick.
        Http::fake(['*' => Http::response(['status' => 0, 'errors' => ['bad token']], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        collect(range(1, 5))->each(fn ($i) => $this->queueBulkBlockedIp("10.0.0.{$i}"));

        $this->artisan('gateway:sync-tor-bulk-block-to-cpanel')->assertSuccessful();

        // Exactly one attempt per record this run, not a repeated retry storm.
        Http::assertSentCount(5);
        $this->assertSame(5, GatewayBlockedIp::query()->whereNotNull('cpanel_sync_error')->count());
    }
}
