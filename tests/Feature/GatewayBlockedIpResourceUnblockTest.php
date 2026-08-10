<?php

namespace Tests\Feature;

use App\Filament\Resources\GatewayBlockedIpResource\Pages\ListGatewayBlockedIps;
use App\Models\GatewayBlockedIp;
use App\Models\User;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

// A manual "Unblock" (single row or bulk) previously only flipped is_active locally, leaving the
// IP rejected by cPanel forever - AutoBlockAbusiveIpsCommand's expiry sweep only ever looks at
// records still marked is_active, so a record unblocked this way would never be revisited. This
// matters for the "gradually restore after a week" plan a bulk Tor block is meant to support.
class GatewayBlockedIpResourceUnblockTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function syncedRecord(string $ip = '10.0.0.1'): GatewayBlockedIp
    {
        Http::fake(['*' => Http::response(['status' => 1], 200)]);

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token',
        );

        $record = GatewayBlockedIp::create(['ip' => $ip, 'is_active' => true]);
        app(\App\Services\CpanelIpBlockerService::class)->block($record);
        $this->assertNotNull($record->fresh()->cpanel_synced_at);

        Http::fake(['*/remove_ip*' => Http::response(['status' => 1], 200)]);

        return $record->fresh();
    }

    public function test_the_single_row_toggle_action_unblocks_at_cpanel_too(): void
    {
        $this->actingAsSuperAdmin();
        $record = $this->syncedRecord();

        Livewire::test(ListGatewayBlockedIps::class)
            ->callTableAction('toggle', $record);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/remove_ip') && str_contains($request->url(), 'ip=10.0.0.1'));
        $this->assertNull($record->fresh()->cpanel_synced_at);
        $this->assertFalse($record->fresh()->is_active);
    }

    public function test_the_bulk_unblock_action_unblocks_every_selected_record_at_cpanel(): void
    {
        $this->actingAsSuperAdmin();
        $first = $this->syncedRecord('10.0.0.1');
        $second = $this->syncedRecord('10.0.0.2');

        Livewire::test(ListGatewayBlockedIps::class)
            ->callTableBulkAction('unblock', [$first, $second]);

        Http::assertSentCount(2);
        $this->assertNull($first->fresh()->cpanel_synced_at);
        $this->assertNull($second->fresh()->cpanel_synced_at);
        $this->assertFalse($first->fresh()->is_active);
        $this->assertFalse($second->fresh()->is_active);
    }
}
