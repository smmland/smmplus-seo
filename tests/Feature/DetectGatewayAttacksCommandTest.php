<?php

namespace Tests\Feature;

use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Models\PanelNotification;
use App\Models\TelegramAlertRecipient;
use App\Services\TelegramAlertSettingsService;
use App\Services\TelegramSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DetectGatewayAttacksCommandTest extends TestCase
{
    use RefreshDatabase;

    private function enableTelegramAlerts(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnAttackDetectedEnabled(true);
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);
    }

    private function logBlockedRequests(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            GatewayRequestLog::create(['ip' => "10.0.0.{$i}", 'status' => GatewayRequestLog::STATUS_BLOCKED_IP]);
        }
    }

    public function test_no_op_when_under_the_threshold(): void
    {
        $this->logBlockedRequests(5);

        $this->artisan('gateway:detect-attacks')->assertSuccessful();

        $this->assertSame(0, PanelNotification::query()->count());
    }

    public function test_detects_an_attack_and_notifies_once(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->logBlockedRequests(10);

        $this->artisan('gateway:detect-attacks')->assertSuccessful();

        $notification = PanelNotification::query()->where('type', 'attack_detected')->first();
        $this->assertNotNull($notification);
        $this->assertSame('security', $notification->category);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', 'Possible attack detected'));
    }

    public function test_does_not_renotify_while_the_attack_continues(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->logBlockedRequests(10);

        $this->artisan('gateway:detect-attacks')->assertSuccessful();
        $this->logBlockedRequests(10);
        $this->artisan('gateway:detect-attacks')->assertSuccessful();

        $this->assertSame(1, PanelNotification::query()->where('type', 'attack_detected')->count());
        Http::assertSentCount(1);
    }

    public function test_detects_the_attack_subsiding_and_notifies_with_a_summary(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        Cache::forever('gateway_attack_started_at', now()->subMinutes(5)->toIso8601String());

        // created_at isn't mass-assignable - set it directly so it's backdated into the incident
        // window, since GatewayBlockedIp::create() would otherwise stamp it as "now".
        foreach (['1.2.3.4' => 3, '5.6.7.8' => 2] as $ip => $minutesAgo) {
            $record = new GatewayBlockedIp(['ip' => $ip, 'is_active' => true]);
            $record->created_at = now()->subMinutes($minutesAgo);
            $record->save();
        }

        // Now below the threshold - the attack has stopped.
        $this->artisan('gateway:detect-attacks')->assertSuccessful();

        $notification = PanelNotification::query()->where('type', 'attack_subsided')->first();
        $this->assertNotNull($notification);
        $this->assertStringContainsString('2 IP(s)', $notification->body);

        $this->assertNull(Cache::get('gateway_attack_started_at'));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', 'subsided'));
    }

    public function test_no_telegram_alert_when_the_toggle_is_off(): void
    {
        $this->enableTelegramAlerts();
        app(TelegramAlertSettingsService::class)->setOnAttackDetectedEnabled(false);
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        $this->logBlockedRequests(10);

        $this->artisan('gateway:detect-attacks')->assertSuccessful();

        $this->assertNotNull(PanelNotification::query()->where('type', 'attack_detected')->first());
        Http::assertNothingSent();
    }
}
