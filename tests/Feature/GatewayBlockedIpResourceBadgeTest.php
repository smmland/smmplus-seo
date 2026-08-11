<?php

namespace Tests\Feature;

use App\Filament\Resources\GatewayBlockedIpResource;
use App\Filament\Resources\GatewayBlockedIpResource\Pages\ListGatewayBlockedIps;
use App\Models\User;
use App\Services\PanelNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class GatewayBlockedIpResourceBadgeTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    public function test_badge_reflects_unread_attack_notifications(): void
    {
        $this->actingAsSuperAdmin();

        app(PanelNotificationService::class)->notify(
            'security', 'attack_detected', 'Possible attack detected', null, GatewayBlockedIpResource::getUrl(),
        );

        $this->assertSame('1', GatewayBlockedIpResource::getNavigationBadge());
    }

    public function test_visiting_the_page_clears_the_badge(): void
    {
        $this->actingAsSuperAdmin();

        app(PanelNotificationService::class)->notify(
            'security', 'attack_detected', 'Possible attack detected', null, GatewayBlockedIpResource::getUrl(),
        );

        $this->assertSame('1', GatewayBlockedIpResource::getNavigationBadge());

        Livewire::test(ListGatewayBlockedIps::class);

        $this->assertNull(GatewayBlockedIpResource::getNavigationBadge());
    }

    public function test_badge_is_null_when_nothing_is_unread(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertNull(GatewayBlockedIpResource::getNavigationBadge());
    }
}
