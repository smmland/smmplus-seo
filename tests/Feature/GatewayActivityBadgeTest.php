<?php

namespace Tests\Feature;

use App\Livewire\GatewayActivityBadge;
use App\Models\GatewayRequestLog;
use App\Models\User;
use App\Support\PanelSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayActivityBadgeTest extends TestCase
{
    use RefreshDatabase;

    public function test_shows_the_count_for_a_user_with_access(): void
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        GatewayRequestLog::create(['ip' => '1.2.3.4', 'status' => GatewayRequestLog::STATUS_SUCCESS]);
        GatewayRequestLog::create(['ip' => '1.2.3.5', 'status' => GatewayRequestLog::STATUS_BLOCKED_IP]);

        Livewire::test(GatewayActivityBadge::class)
            ->assertSee('Gateway 2')
            ->assertSee('1 blocked');
    }

    public function test_shows_nothing_for_a_user_without_gateway_or_security_access(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'granted_sections' => [PanelSection::key(PanelSection::TRANSLATION, PanelSection::TIER_VIEW)],
        ]);
        $this->actingAs($user);

        GatewayRequestLog::create(['ip' => '1.2.3.4', 'status' => GatewayRequestLog::STATUS_SUCCESS]);

        Livewire::test(GatewayActivityBadge::class)
            ->assertDontSee('Gateway');
    }
}
