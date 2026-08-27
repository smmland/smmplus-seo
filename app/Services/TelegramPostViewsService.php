<?php

namespace App\Services;

use App\Models\GatewayUpstream;
use App\Models\TelegramPost;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Reads a recent post's public counter and orders only the shortfall from the configured target,
 * using the same UAPI-style upstream contract as FreeServiceController. The service id lives in
 * a private Telegram settings namespace and is never published through the free-service catalog.
 * A post-level delivery cool-down prevents repeatedly ordering the same shortfall before the
 * upstream provider has had time to deliver the previous request.
 */
class TelegramPostViewsService
{
    public function __construct(
        private readonly TelegramAutoViewsSettingsService $settings,
        private readonly TelegramSettingsService $telegramSettings,
        private readonly TelegramPostViewCountService $viewCounts,
    ) {}

    /** @return 'disabled'|'healthy'|'cooldown'|'ordered'|'failed' */
    public function topUpViewsFor(TelegramPost $post): string
    {
        if (! $this->settings->isEnabled()) {
            return 'disabled';
        }

        $upstreamId = $this->settings->getUpstreamId();
        $serviceId = $this->settings->getServiceId();

        if (! $upstreamId || ! $serviceId) {
            $post->update(['views_order_error' => 'Automatic post views is enabled but not fully configured (Telegram Settings).']);

            return 'failed';
        }

        $link = $this->buildPostLink($post);

        if (! $link) {
            $post->update(['views_order_error' => 'Could not build a public link for this post - the configured Telegram channel id isn\'t a public @username channel.']);

            return 'failed';
        }

        $upstream = GatewayUpstream::query()->find($upstreamId);

        if (! $upstream || ! $upstream->is_active) {
            $post->update(['views_order_error' => 'The configured upstream provider (Telegram Settings) is missing or inactive.']);

            return 'failed';
        }

        try {
            $currentViews = $this->viewCounts->get((string) $this->telegramSettings->getChannelId(), (int) $post->telegram_message_id);
        } catch (\Throwable $e) {
            $post->update([
                'views_checked_at' => now(),
                'views_order_error' => $e->getMessage(),
            ]);

            Log::warning('Telegram post views: counter check failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);

            return 'failed';
        }

        $post->update([
            'observed_views' => $currentViews,
            'views_checked_at' => now(),
            'views_order_error' => null,
        ]);

        $quantity = $this->settings->getTarget() - $currentViews;

        if ($quantity <= 0) {
            return 'healthy';
        }

        if ($post->views_ordered_at?->greaterThan(now()->subHours($this->settings->getCooldownHours()))) {
            return 'cooldown';
        }

        try {
            $response = Http::asForm()->timeout(30)->post($upstream->base_url, [
                'key' => $upstream->api_key,
                'action' => 'add',
                'service' => $serviceId,
                'link' => $link,
                'quantity' => $quantity,
            ]);
        } catch (\Throwable $e) {
            $post->update(['views_order_error' => $e->getMessage()]);

            Log::warning('Telegram post views: order request failed', ['post_id' => $post->id, 'error' => $e->getMessage()]);

            return 'failed';
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

            return 'failed';
        }

        $post->update([
            'views_ordered_at' => now(),
            'views_order_error' => null,
            'views_last_order_quantity' => $quantity,
            'views_upstream_order_id' => isset($data['order']) ? (string) $data['order'] : null,
        ]);

        return 'ordered';
    }

    /** @deprecated Scheduled top-up checks should call topUpViewsFor(). */
    public function orderViewsFor(TelegramPost $post): void
    {
        $this->topUpViewsFor($post);
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
