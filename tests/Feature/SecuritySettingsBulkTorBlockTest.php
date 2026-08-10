<?php

namespace Tests\Feature;

use App\Filament\Pages\SecuritySettings;
use App\Models\GatewayBlockedIp;
use App\Models\User;
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

    private function seedTorList(int $count = 150): void
    {
        $ips = collect(range(1, $count))->map(fn ($i) => "10.0.0.{$i}")->implode("\n");
        Http::fake(['*' => Http::response($ips, 200)]);
        app(TorExitNodeListService::class)->refresh();
    }

    public function test_queues_every_exit_node_ip_for_blocking(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedTorList();

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        $this->assertSame(150, GatewayBlockedIp::query()->where('is_active', true)->count());

        $record = GatewayBlockedIp::query()->where('ip', '10.0.0.1')->first();
        $this->assertNotNull($record);
        $this->assertStringStartsWith('Tor exit-node bulk block', $record->note);
        $this->assertNull($record->cpanel_synced_at);
        $this->assertNotNull($record->blocked_until);
    }

    public function test_does_not_touch_ips_already_actively_blocked(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedTorList();

        $existing = GatewayBlockedIp::create([
            'ip' => '10.0.0.1',
            'note' => 'Manually blocked earlier',
            'is_active' => true,
            'offense_count' => 3,
        ]);

        Livewire::test(SecuritySettings::class)->call('blockAllTorExitNodes');

        $this->assertSame('Manually blocked earlier', $existing->fresh()->note);
        $this->assertSame(149, GatewayBlockedIp::query()->where('note', 'like', 'Tor exit-node bulk block%')->count());
    }

    public function test_is_a_no_op_when_the_tor_list_is_empty(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        $this->assertSame(0, GatewayBlockedIp::query()->count());
    }

    public function test_is_a_no_op_when_every_exit_node_is_already_blocked(): void
    {
        $this->actingAsSuperAdmin();
        $this->seedTorList(3);

        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            GatewayBlockedIp::create(['ip' => $ip, 'is_active' => true]);
        }

        Livewire::test(SecuritySettings::class)
            ->call('blockAllTorExitNodes')
            ->assertNotified();

        $this->assertSame(0, GatewayBlockedIp::query()->where('note', 'like', 'Tor exit-node bulk block%')->count());
    }
}
