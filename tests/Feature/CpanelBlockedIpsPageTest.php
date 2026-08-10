<?php

namespace Tests\Feature;

use App\Filament\Pages\CpanelBlockedIps;
use App\Models\GatewayBlockedIp;
use App\Models\User;
use App\Services\GatewaySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CpanelBlockedIpsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function configureCpanel(): void
    {
        app(GatewaySettingsService::class)->setCpanelBlockerSettings(
            true, 'server1.example.com:2083', 'someuser', 'secret-token', 'public_html/.htaccess',
        );
    }

    public function test_lists_ips_parsed_from_the_live_htaccess_content(): void
    {
        $this->actingAsSuperAdmin();
        $this->configureCpanel();

        Http::fake(['*get_file_content*' => Http::response([
            'status' => 1,
            'data' => ['content' => "deny from 1.2.3.4\ndeny from 5.6.7.8\n"],
        ], 200)]);

        Livewire::test(CpanelBlockedIps::class)
            ->assertSee('1.2.3.4')
            ->assertSee('5.6.7.8');
    }

    public function test_shows_the_error_when_the_fetch_fails(): void
    {
        $this->actingAsSuperAdmin();

        Livewire::test(CpanelBlockedIps::class)
            ->assertSee('not fully configured');
    }

    public function test_shows_the_local_note_for_an_ip_this_panel_blocked(): void
    {
        $this->actingAsSuperAdmin();
        $this->configureCpanel();

        GatewayBlockedIp::create(['ip' => '1.2.3.4', 'note' => 'Tor exit node (expires in 7d)', 'is_active' => true]);

        Http::fake(['*get_file_content*' => Http::response([
            'status' => 1,
            'data' => ['content' => "deny from 1.2.3.4\n"],
        ], 200)]);

        Livewire::test(CpanelBlockedIps::class)
            ->assertSee('Tor exit node (expires in 7d)');
    }

    public function test_unblock_calls_remove_ip_and_marks_the_local_record_inactive(): void
    {
        $this->actingAsSuperAdmin();
        $this->configureCpanel();

        $record = GatewayBlockedIp::create(['ip' => '1.2.3.4', 'note' => 'Manual block', 'is_active' => true]);

        Http::fake([
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => "deny from 1.2.3.4\n"]], 200),
            '*remove_ip*' => Http::response(['status' => 1], 200),
        ]);

        Livewire::test(CpanelBlockedIps::class)
            ->call('unblock', '1.2.3.4')
            ->assertNotified();

        Http::assertSent(fn ($request) => str_contains($request->url(), '/remove_ip') && str_contains($request->url(), 'ip=1.2.3.4'));
        $this->assertFalse($record->fresh()->is_active);
    }

    public function test_unblock_creates_a_local_record_for_an_ip_that_had_none(): void
    {
        $this->actingAsSuperAdmin();
        $this->configureCpanel();

        Http::fake([
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => "deny from 9.9.9.9\n"]], 200),
            '*remove_ip*' => Http::response(['status' => 1], 200),
        ]);

        Livewire::test(CpanelBlockedIps::class)->call('unblock', '9.9.9.9');

        $record = GatewayBlockedIp::query()->where('ip', '9.9.9.9')->first();
        $this->assertNotNull($record);
        $this->assertFalse($record->is_active);
    }

    public function test_unblock_does_not_mark_the_record_inactive_when_the_cpanel_call_fails(): void
    {
        $this->actingAsSuperAdmin();
        $this->configureCpanel();

        $record = GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => true]);

        Http::fake([
            '*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => "deny from 1.2.3.4\n"]], 200),
            '*remove_ip*' => Http::response(['status' => 0, 'errors' => ['nope']], 200),
        ]);

        Livewire::test(CpanelBlockedIps::class)
            ->call('unblock', '1.2.3.4')
            ->assertNotified();

        $this->assertTrue($record->fresh()->is_active);
    }

    public function test_unblock_requires_edit_access(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'granted_sections' => ['security_view']]);
        $this->actingAs($user);
        $this->configureCpanel();

        $record = GatewayBlockedIp::create(['ip' => '1.2.3.4', 'is_active' => true]);

        Http::fake(['*get_file_content*' => Http::response(['status' => 1, 'data' => ['content' => "deny from 1.2.3.4\n"]], 200)]);

        Livewire::test(CpanelBlockedIps::class)
            ->call('unblock', '1.2.3.4')
            ->assertNotified();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/remove_ip'));
        $this->assertTrue($record->fresh()->is_active);
    }
}
