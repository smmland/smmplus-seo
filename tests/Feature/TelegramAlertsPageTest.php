<?php

namespace Tests\Feature;

use App\Filament\Pages\TelegramAlerts;
use App\Models\TelegramAlertRecipient;
use App\Models\User;
use App\Services\TelegramAlertSettingsService;
use App\Services\TelegramSettingsService;
use App\Support\PanelSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramAlertsPageTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTelegramAdmin(): void
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'granted_sections' => [PanelSection::key(PanelSection::TELEGRAM, PanelSection::TIER_SETTINGS)],
        ]);
        $this->actingAs($user);
    }

    public function test_send_test_alert_warns_when_there_are_no_connected_recipients(): void
    {
        $this->actingAsTelegramAdmin();
        TelegramAlertRecipient::create(['label' => 'Pending', 'link_token' => 'tok', 'chat_id' => null]);

        Http::fake();

        Livewire::test(TelegramAlerts::class)
            ->call('sendTestAlert')
            ->assertNotified('No connected recipients yet');

        Http::assertNothingSent();
    }

    public function test_send_test_alert_reports_success_to_all_connected_recipients(): void
    {
        $this->actingAsTelegramAdmin();
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        Livewire::test(TelegramAlerts::class)
            ->call('sendTestAlert')
            ->assertNotified('Test alert sent');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/sendMessage'));
    }

    public function test_send_test_alert_reports_the_specific_failure_reason(): void
    {
        $this->actingAsTelegramAdmin();
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);

        Livewire::test(TelegramAlerts::class)
            ->call('sendTestAlert')
            ->assertNotified('Test alert failed for 1 of 1 recipient(s)');
    }

    public function test_send_test_alert_ignores_the_per_event_and_master_toggles(): void
    {
        $this->actingAsTelegramAdmin();
        app(TelegramAlertSettingsService::class)->setEnabled(false);
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        Livewire::test(TelegramAlerts::class)
            ->call('sendTestAlert')
            ->assertNotified('Test alert sent');
    }
}
