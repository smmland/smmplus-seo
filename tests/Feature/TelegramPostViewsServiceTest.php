<?php

namespace Tests\Feature;

use App\Models\GatewayUpstream;
use App\Models\TelegramPost;
use App\Services\TelegramAutoViewsSettingsService;
use App\Services\TelegramPostViewsService;
use App\Services\TelegramSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramPostViewsServiceTest extends TestCase
{
    use RefreshDatabase;

    private function sentPost(): TelegramPost
    {
        return TelegramPost::create([
            'type' => TelegramPost::TYPE_CUSTOM,
            'lang' => 'en',
            'title' => 'Test post',
            'message_text' => 'Hello world',
            'scheduled_at' => now(),
            'status' => TelegramPost::STATUS_SENT,
            'sent_at' => now(),
            'telegram_message_id' => 42,
        ]);
    }

    private function configure(): GatewayUpstream
    {
        $upstream = GatewayUpstream::create([
            'name' => 'Provider 1', 'base_url' => 'https://provider.example.com/api/v2', 'api_key' => 'secret', 'is_active' => true,
        ]);

        app(TelegramAutoViewsSettingsService::class)->setSettings(true, $upstream->id, '4512', 500);

        return $upstream;
    }

    public function test_build_post_link_for_a_public_username_channel(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $post = $this->sentPost();

        $link = app(TelegramPostViewsService::class)->buildPostLink($post);

        $this->assertSame('https://t.me/testchannel/42', $link);
    }

    public function test_build_post_link_is_null_for_a_numeric_channel_id(): void
    {
        app(TelegramSettingsService::class)->setChannelId('-1001234567890');
        $post = $this->sentPost();

        $this->assertNull(app(TelegramPostViewsService::class)->buildPostLink($post));
    }

    public function test_build_post_link_is_null_without_a_telegram_message_id(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $post = TelegramPost::create([
            'type' => TelegramPost::TYPE_CUSTOM, 'lang' => 'en', 'title' => 'x', 'message_text' => 'x',
            'scheduled_at' => now(), 'status' => TelegramPost::STATUS_PENDING,
        ]);

        $this->assertNull(app(TelegramPostViewsService::class)->buildPostLink($post));
    }

    public function test_orders_views_and_records_success(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $upstream = $this->configure();
        $post = $this->sentPost();

        Http::fake([
            'https://t.me/*' => Http::response('<span class="tgme_widget_message_views">320</span>', 200),
            'https://provider.example.com/*' => Http::response(['order' => 55123], 200),
        ]);

        app(TelegramPostViewsService::class)->orderViewsFor($post);

        Http::assertSent(function ($request) use ($upstream) {
            return $request->url() === $upstream->base_url
                && $request['action'] === 'add'
                && $request['service'] === '4512'
                && $request['link'] === 'https://t.me/testchannel/42'
                && $request['quantity'] === 180;
        });

        $post->refresh();
        $this->assertNotNull($post->views_ordered_at);
        $this->assertNull($post->views_order_error);
        $this->assertSame(320, $post->observed_views);
        $this->assertSame(180, $post->views_last_order_quantity);
        $this->assertSame('55123', $post->views_upstream_order_id);
    }

    public function test_is_a_no_op_when_disabled(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $post = $this->sentPost();

        Http::fake();

        app(TelegramPostViewsService::class)->orderViewsFor($post);

        Http::assertNothingSent();
        $this->assertNull($post->fresh()->views_ordered_at);
    }

    public function test_records_an_error_when_enabled_but_not_configured(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        app(TelegramAutoViewsSettingsService::class)->setSettings(true, null, null, 500);
        $post = $this->sentPost();

        Http::fake();

        app(TelegramPostViewsService::class)->orderViewsFor($post);

        Http::assertNothingSent();
        $this->assertNotNull($post->fresh()->views_order_error);
    }

    public function test_records_an_error_when_the_channel_has_no_public_link(): void
    {
        app(TelegramSettingsService::class)->setChannelId('-1001234567890');
        $this->configure();
        $post = $this->sentPost();

        Http::fake();

        app(TelegramPostViewsService::class)->orderViewsFor($post);

        Http::assertNothingSent();
        $this->assertStringContainsString('public @username', $post->fresh()->views_order_error);
    }

    public function test_records_an_upstream_error_without_throwing(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $this->configure();
        $post = $this->sentPost();

        Http::fake([
            'https://t.me/*' => Http::response('<span class="tgme_widget_message_views">100</span>', 200),
            'https://provider.example.com/*' => Http::response(['error' => 'Not enough funds'], 200),
        ]);

        app(TelegramPostViewsService::class)->orderViewsFor($post);

        $post->refresh();
        $this->assertNull($post->views_ordered_at);
        $this->assertStringContainsString('Not enough funds', $post->views_order_error);
    }

    public function test_does_not_order_when_the_post_is_already_at_target(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $this->configure();
        $post = $this->sentPost();

        Http::fake(['https://t.me/*' => Http::response('<span class="tgme_widget_message_views">501</span>', 200)]);

        $result = app(TelegramPostViewsService::class)->topUpViewsFor($post);

        $this->assertSame('healthy', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'provider.example.com'));
        $this->assertSame(501, $post->fresh()->observed_views);
    }

    public function test_cooldown_prevents_a_duplicate_order_while_delivery_is_pending(): void
    {
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        $this->configure();
        $post = $this->sentPost();
        $post->update(['views_ordered_at' => now()->subHour()]);

        Http::fake(['https://t.me/*' => Http::response('<span class="tgme_widget_message_views">250</span>', 200)]);

        $result = app(TelegramPostViewsService::class)->topUpViewsFor($post);

        $this->assertSame('cooldown', $result);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'provider.example.com'));
    }
}
