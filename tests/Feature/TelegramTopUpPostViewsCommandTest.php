<?php

namespace Tests\Feature;

use App\Models\GatewayUpstream;
use App\Models\TelegramPost;
use App\Services\TelegramAutoViewsSettingsService;
use App\Services\TelegramSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TelegramTopUpPostViewsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_orders_only_the_shortfall_for_recent_sent_posts(): void
    {
        $upstream = GatewayUpstream::create([
            'name' => 'Provider',
            'base_url' => 'https://provider.example.com/api',
            'api_key' => 'secret',
            'is_active' => true,
        ]);

        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        app(TelegramAutoViewsSettingsService::class)->setSettings(true, $upstream->id, '77', 500, 30, 12, 20);

        TelegramPost::create([
            'type' => TelegramPost::TYPE_CUSTOM,
            'lang' => 'en',
            'title' => 'Recent post',
            'message_text' => 'Post',
            'scheduled_at' => now()->subHour(),
            'status' => TelegramPost::STATUS_SENT,
            'sent_at' => now()->subHour(),
            'telegram_message_id' => 42,
        ]);

        Http::fake([
            'https://t.me/*' => Http::response('<span class="tgme_widget_message_views">480</span>', 200),
            'https://provider.example.com/*' => Http::response(['order' => 123], 200),
        ]);

        $this->artisan('telegram:top-up-post-views')->assertSuccessful();

        Http::assertSent(fn ($request) => str_contains($request->url(), 'provider.example.com')
            && $request['quantity'] === 20
            && $request['link'] === 'https://t.me/testchannel/42');
    }

    public function test_it_ignores_old_unsent_and_very_new_posts(): void
    {
        $upstream = GatewayUpstream::create([
            'name' => 'Provider', 'base_url' => 'https://provider.example.com/api', 'api_key' => 'secret', 'is_active' => true,
        ]);
        app(TelegramSettingsService::class)->setChannelId('@testchannel');
        app(TelegramAutoViewsSettingsService::class)->setSettings(true, $upstream->id, '77', 500, 30, 12, 20);

        foreach ([
            [TelegramPost::STATUS_SENT, now()->subDays(31), 1],
            [TelegramPost::STATUS_PENDING, null, 2],
            [TelegramPost::STATUS_SENT, now()->subMinute(), 3],
        ] as [$status, $sentAt, $messageId]) {
            TelegramPost::create([
                'type' => TelegramPost::TYPE_CUSTOM, 'lang' => 'en', 'title' => 'Ignored', 'message_text' => 'Post',
                'scheduled_at' => now(), 'status' => $status, 'sent_at' => $sentAt, 'telegram_message_id' => $messageId,
            ]);
        }

        Http::fake();

        $this->artisan('telegram:top-up-post-views')->assertSuccessful();

        Http::assertNothingSent();
    }
}
