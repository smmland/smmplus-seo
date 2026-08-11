<?php

namespace App\Services;

use App\Models\GatewayUpstream;
use App\Models\TelegramPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Automatically orders views for a Telegram post once it's actually sent, via one of the same
 * upstream SMM providers the Free Service Gateway already talks to (same UAPI-style
 * action=add call FreeServiceController makes) - but through its own settings namespace
 * (TelegramAutoViewsSettingsService), never the public GatewayService catalog, so this is never
 * reachable through the public /api/free-service/order endpoint. Expects the configured upstream
 * service id to itself be a drip-feed variant - this places a single order for the full quantity
 * and relies on the provider's own pacing to deliver it gradually, the same way an admin placing
 * that order by hand through the provider's own panel would.
 */
class TelegramPostViewsService
{
    public function __construct(
        private readonly TelegramAutoViewsSettingsService $settings,
        private readonly TelegramSettingsService $telegramSettings,
    ) {}

    public function orderViewsFor(TelegramPost $post): void
    {
        if (! $this->settings->isEnabled()) {
            return;
        }

        $upstreamId = $this->settings->getUpstreamId();
        $serviceId = $this->settings->getServiceId();

        if (! $upstreamId || ! $serviceId) {
            $post->update(['views_order_error' => 'Automatic post views is enabled but not fully configured (Telegram Settings).']);

            return;
        }

        $link = $this->buildPostLink($post);

        if (! $link) {
            $post->update(['views_order_error' => 'Could not build a public link for this post - the configured Telegram channel id isn\'t a public @username channel.']);

            return;
        }

        $upstream = GatewayUpstream::query()->find($upstreamId);

        if (! $upstream || ! $upstream->is_active) {
            $post->update(['views_order_error' => 'The configured upstream provider (Telegram Settings) is missing or inactive.']);

            return;
        }

        try {
            $response = Http::asForm()->timeout(30)->post($upstream->base_url, [
                'key' => $upstream->api_key,
                'action' => 'add',
                'service' => $serviceId,
                'link' => $link,
                'quantity' => $this->settings->getQuantity(),
            ]);
        } catch (\Throwable $e) {
            $post->update(['views_order_error' => $e->getMessage()]);

            Log::warning('Telegram post views: order request failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);

            return;
        }

        $data = $response->json();

        if ($response->status() >= 400 || ! is_array($data) || isset($data['error'])) {
            $reason = is_array($data) ? ($data['error'] ?? $response->body()) : $response->body();
            $post->update(['views_order_error' => "HTTP {$response->status()}: {$reason}"]);

            Log::warning('Telegram post views: order failed', [
                'post_id' => $post->id,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            return;
        }

        $post->update(['views_ordered_at' => now(), 'views_order_error' => null]);
    }

    // Only ever buildable for a public @username channel - a numeric chat_id has no public t.me
    // permalink to give an upstream views provider in the first place.
    public function buildPostLink(TelegramPost $post): ?string
    {
        if (! $post->telegram_message_id) {
            return null;
        }

        $channelId = $this->telegramSettings->getChannelId();

        if (! $channelId || ! str_starts_with($channelId, '@')) {
            return null;
        }

        $username = ltrim($channelId, '@');

        return "https://t.me/{$username}/{$post->telegram_message_id}";
    }
}
