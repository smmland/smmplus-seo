<?php

namespace Tests\Feature;

use App\Models\BlogTranslationJob;
use App\Models\CategoryTranslationJob;
use App\Models\ServiceTranslationJob;
use App\Services\BlogAiTranslationService;
use App\Services\CategoryAiTranslationService;
use App\Services\ServiceAiTranslationService;
use App\Services\TelegramAlertSettingsService;
use App\Services\TelegramSettingsService;
use App\Models\TelegramAlertRecipient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Reproduces the gap the admin reported: "translation completed" notifications showed up in the
// panel's notification bell but were never DMed via Telegram, unlike every other alert-able
// event. Each of the three translation-queue commands has to actually trigger the Telegram send,
// not just the in-panel one.
class TranslationCompletedTelegramAlertTest extends TestCase
{
    use RefreshDatabase;

    private function enableTelegramAlerts(): void
    {
        app(TelegramAlertSettingsService::class)->setEnabled(true);
        app(TelegramAlertSettingsService::class)->setOnTranslationCompletedEnabled(true);
        app(TelegramSettingsService::class)->setBotToken('test-token');
        TelegramAlertRecipient::create(['label' => 'Ali', 'link_token' => 'tok', 'chat_id' => '123']);
    }

    public function test_blog_queue_sends_a_telegram_alert_when_a_job_completes(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::QUEUED]);

        $this->mock(BlogAiTranslationService::class)
            ->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturn([$job->id => ['ok' => true, 'message' => 'Translated.']]);

        $this->artisan('translation:process-queue')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', '1 blog translation(s) completed'));
    }

    public function test_service_queue_sends_a_telegram_alert_when_a_job_completes(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $job = ServiceTranslationJob::create([
            'service_key' => 's1', 'target_lang' => 'fr', 'field' => ServiceTranslationJob::FIELD_TITLE,
            'status' => ServiceTranslationJob::QUEUED,
        ]);

        $this->mock(ServiceAiTranslationService::class)
            ->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturn([$job->id => ['ok' => true, 'message' => 'Translated.']]);

        $this->artisan('services:process-queue')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', '1 service translation(s) completed'));
    }

    public function test_category_queue_sends_a_telegram_alert_when_a_job_completes(): void
    {
        $this->enableTelegramAlerts();
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $job = CategoryTranslationJob::create(['category_id' => 1, 'target_lang' => 'fr', 'status' => CategoryTranslationJob::QUEUED]);

        $this->mock(CategoryAiTranslationService::class)
            ->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturn([$job->id => ['ok' => true, 'message' => 'Translated.']]);

        $this->artisan('categories:process-queue')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'api.telegram.org')
            && str_contains($request['text'] ?? '', '1 category translation(s) completed'));
    }

    public function test_no_telegram_alert_when_the_event_toggle_is_off(): void
    {
        $this->enableTelegramAlerts();
        app(TelegramAlertSettingsService::class)->setOnTranslationCompletedEnabled(false);
        Http::fake(['*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]], 200)]);

        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::QUEUED]);

        $this->mock(BlogAiTranslationService::class)
            ->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturn([$job->id => ['ok' => true, 'message' => 'Translated.']]);

        $this->artisan('translation:process-queue')->assertSuccessful();

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'api.telegram.org'));
    }
}
