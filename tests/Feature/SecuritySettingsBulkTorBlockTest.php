<?php

namespace Tests\Feature;

use App\Filament\Pages\SecuritySettings;
use App\Models\GatewayBlockedIp;
use App\Models\User;
use App\Services\GatewaySettingsService;
use App\Services\TorExitNodeListService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class SecuritySettingsBulkTorBlockTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    // Seeds the Tor exit-node cache and stubs cPanel's Fileman endpoints in one shot - refresh()
    // itself needs Http::fake active, so this has to happen before configuring cPanel credentials
    // and before the Livewire call under test.
    private function seedTorListAndCpanel(int $count = 150, string $existingHtaccess = ''): void
    {
        $ips = collect(range(1, $count))->map(fn ($i) => "10.0.0.{$i}")->implode("\n");

        Http::fake([
            '*torbulkexitlist*' => Http::response($ips, 200),
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => $existingHtaccess]], 200),
            '*save_file_content*' => Http::response(['status' => 1], 200),
        ]);

        app(TorExitNodeListService::class)->refresh();

        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );
    }

    public function test_blocks_every_exit_node_ip_by_writing_directly_to_htaccess(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedTorListAndCpanel();

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        Http::assertSentCount(3); // torbulkexitlist refresh + one Fileman read + one Fileman write
        $this->assertSame(150, GatewayBlockedIp::query()->where('is_active', true)->count());

        $record = GatewayBlockedIp::query()->where('ip', '10.0.0.1')->first();
        $this->assertNotNull($record);
        $this->assertStringStartsWith('Tor exit-node bulk block', $record->note);
        $this->assertNotNull($record->cpanel_synced_at);
        $this->assertNotNull($record->blocked_until);
    }

    public function test_only_writes_ips_not_already_present_in_the_live_htaccess(): void
    {
        $this->actingAsSuperAdmin();
        // Below TorExitNodeListService::MIN_PLAUSIBLE_COUNT gets rejected as "too small" and
        // never cached, so this needs a realistically-sized list like the other tests here.
        $this->seedTorListAndCpanel(150, "deny from 10.0.0.1\n");

        Livewire::test(SecuritySettings::class)->call('blockAllTorExitNodes');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'save_file_content')) {
                return true;
            }

            return substr_count($request['content'], 'deny from 10.0.0.1') === 1
                && str_contains($request['content'], 'deny from 10.0.0.2')
                && str_contains($request['content'], 'deny from 10.0.0.150');
        });

        // Local bookkeeping still reflects all 150 as currently blocked, even though only 149
        // were newly written to the file itself.
        $this->assertSame(150, GatewayBlockedIp::query()->where('is_active', true)->count());
    }

    public function test_is_a_no_op_when_the_tor_list_is_empty(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        $this->assertSame(0, GatewayBlockedIp::query()->count());
    }

    public function test_is_a_no_op_when_every_exit_node_is_already_in_the_htaccess(): void
    {
        $this->actingAsSuperAdmin();
        $existing = collect(range(1, 150))->map(fn ($i) => "deny from 10.0.0.{$i}")->implode("\n")."\n";
        $this->seedTorListAndCpanel(150, $existing);

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'save_file_content'));
        $this->assertSame(0, GatewayBlockedIp::query()->count());
    }

    public function test_surfaces_a_write_failure_instead_of_updating_local_records(): void
    {
        $this->actingAsSuperAdmin();
        $ips = collect(range(1, 150))->map(fn ($i) => "10.0.0.{$i}")->implode("\n");

        Http::fake([
            '*torbulkexitlist*' => Http::response($ips, 200),
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => '']], 200),
            '*save_file_content*' => Http::response(['status' => 0, 'errors' => ['permission denied']], 200),
        ]);
        app(TorExitNodeListService::class)->refresh();
        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        $this->assertSame(0, GatewayBlockedIp::query()->count());
    }
}
