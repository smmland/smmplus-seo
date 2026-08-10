<?php

namespace Tests\Feature;

use App\Filament\Widgets\GatewayLiveActivityWidget;
use App\Models\GatewayRequestLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayLiveActivityWidgetTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_reports_normal_status_with_no_recent_blocked_requests(): void
    {
        $this->actingAsSuperAdmin();

        GatewayRequestLog::create(['ip' => '1.2.3.4', 'status' => GatewayRequestLog::STATUS_SUCCESS]);

        Livewire::test(GatewayLiveActivityWidget::class)
            ->assertSee('Gateway requests (last minute)')
            ->assertSee('Normal')
            ->assertDontSee('Possible attack');
    }

    public function test_reports_possible_attack_when_blocked_requests_exceed_the_threshold(): void
    {
        $this->actingAsSuperAdmin();

        for ($i = 0; $i < 12; $i++) {
            GatewayRequestLog::create(['ip' => "1.2.3.{$i}", 'status' => GatewayRequestLog::STATUS_BLOCKED_IP]);
        }

        Livewire::test(GatewayLiveActivityWidget::class)
            ->assertSee('Possible attack');
    }

    public function test_ignores_requests_older_than_one_minute(): void
    {
        $this->actingAsSuperAdmin();

        $old = GatewayRequestLog::create(['ip' => '1.2.3.4', 'status' => GatewayRequestLog::STATUS_BLOCKED_IP]);
        $old->update(['created_at' => now()->subMinutes(5)]);

        Livewire::test(GatewayLiveActivityWidget::class)
            ->assertSee('0')
            ->assertSee('Normal');
    }
}
