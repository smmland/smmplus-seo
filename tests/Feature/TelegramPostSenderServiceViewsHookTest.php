<?php

namespace Tests\Feature;

use App\Models\GatewayUpstream;
use App\Models\TelegramPost;
use App\Services\TelegramAutoViewsSettingsService;
use App\Services\TelegramPostSenderService;
use App\Services\TelegramSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPostSenderServiceViewsHookTest extends TestCase
{
    use RefreshDatabase;

    private function pendingPost(): TelegramPost
    {
        return TelegramPost::create([
            'type' => TelegramPost::TYPE_CUSTOM, 'lang' => 'en', 'title' => 'Test',
            'message_text' => 'Hello world', 'scheduled_at' => now(), 'status' => TelegramPost::STATUS_PENDING,
        ]);
    }

    private function configureChannelAndBot(): void
    {
        app(TelegramSettingsService::class)->setEnabled(true);
        app(TelegramSettingsService::class)->setBotToken('bot-token');
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
    }

    private function configureAutoViews(): void
    {
        $upstream = GatewayUpstream::create(['name' => 'P1', 'base_url' => 'https://provider.example.com/api', 'api_key' => 'secret', 'is_active' => true]);
        app(TelegramAutoViewsSettingsService::class)->setSettings(true, $upstream->id, '4512', 1000);
    }

    public function test_a_successful_send_triggers_a_views_order(): void
    {
        $this->configureChannelAndBot();
        $this->configureAutoViews();
        $post = $this->pendingPost();

        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => true, 'result' => ['message_id' => 99]], 200),
            'https://provider.example.com/*' => Http::response(['order' => 1], 200),
        ]);

        app(TelegramPostSenderService::class)->sendNow($post);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider.example.com')
            && $request['link'] === 'https://t.me/testchannel/99');

        $this->assertNotNull($post->fresh()->views_ordered_at);
    }

    public function test_a_failed_send_does_not_trigger_a_views_order(): void
    {
        $this->configureChannelAndBot();
        $this->configureAutoViews();
        $post = $this->pendingPost();

        Http::fake([
            '*api.telegram.org*' => Http::response(['ok' => false, 'description' => 'bad request'], 400),
        ]);

        app(TelegramPostSenderService::class)->sendNow($post);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'provider.example.com'));
        $this->assertNull($post->fresh()->views_ordered_at);
    }
}
