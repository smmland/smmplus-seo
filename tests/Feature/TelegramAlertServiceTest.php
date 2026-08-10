<?php

namespace Tests\Feature;

use App\Models\TelegramAlertRecipient;
use App\Services\TelegramAlertSettingsService;
use App\Services\TelegramAlertService;
use App\Services\TelegramSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class TelegramAlertServiceTest extends TestCase
{
    use RefreshDatabase;

    private function enableAlerts(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnNewServiceEnabled(true);
        app(TelegramSettingsService::class)->setBotToken('test-token');
    }

    public function test_a_failed_send_is_logged_instead_of_silently_discarded(): void
    {
        $this->enableAlerts();
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => false, 'description' => 'Forbidden: bot was blocked by the user'], 403)]);
        Log::spy();

        app(TelegramAlertService::class)->notifyNewService('Test Service', null);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(fn ($message, $context) => $message === 'Telegram alert failed to send'
                && $context['recipient_id'] !== null
                && str_contains($context['error'], 'blocked'));
    }

    public function test_a_successful_send_does_not_log_anything(): void
    {
        $this->enableAlerts();
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);
        Log::spy();

        app(TelegramAlertService::class)->notifyNewService('Test Service', null);

        Log::shouldNotHaveReceived('warning');
    }

    public function test_disabled_events_never_reach_the_http_call(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnNewServiceEnabled(false);
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake();

        app(TelegramAlertService::class)->notifyNewService('Test Service', null);

        Http::assertNothingSent();
    }

    public function test_unlinked_recipients_never_get_a_send_attempt(): void
    {
        $this->enableAlerts();
        TelegramAlertRecipient::create(['label' => 'Pending', 'link_token' => 'tok', 'chat_id' => null]);

        Http::fake();

        app(TelegramAlertService::class)->notifyNewService('Test Service', null);

        Http::assertNothingSent();
    }

    public function test_notify_translation_completed_sends_when_enabled(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnTranslationCompletedEnabled(true);
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake(['*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        app(TelegramAlertService::class)->notifyTranslationCompleted('3 blog translation(s) completed');

        Http::assertSent(fn ($request) => str_contains($request['text'] ?? '', '3 blog translation(s) completed'));
    }

    public function test_notify_translation_completed_is_off_by_default_toggle_respected(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnTranslationCompletedEnabled(false);
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);

        Http::fake();

        app(TelegramAlertService::class)->notifyTranslationCompleted('3 blog translation(s) completed');

        Http::assertNothingSent();
    }
}
