<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

/**
 * Checks for the "featured channel" and "made a video" giveaway tasks - unlike subscribing
 * (which needs the subscriber's own OAuth consent, since subscription status is private), both
 * of these are public data anyone can read: a channel's featured-channels list and a video's own
 * title/description/visibility are visible on YouTube itself with no login. So these use a plain
 * Google API key (YoutubeSettingsService::getYoutubeDataApiKey()) rather than the OAuth flow
 * YoutubeOAuthService handles - no consent screen, no per-check friction for the user.
 */
class YoutubeDataApiService
{
    private const CHANNELS_URL = 'https://www.googleapis.com/youtube/v3/channels';

    private const VIDEOS_URL = 'https://www.googleapis.com/youtube/v3/videos';

    public function __construct(private readonly GiveawaySettingsService $settings) {}

    /**
     * @return array{ok: bool, message: string, isFeatured?: bool, channelId?: string}
     */
    public function checkFeaturedChannel(string $theirChannelInput): array
    {
        $apiKey = $this->settings->getYoutubeDataApiKey();
        $ourChannelId = $this->settings->getYoutubeChannelId();

        if (! $apiKey || ! $ourChannelId) {
            return ['ok' => false, 'message' => 'YouTube API key or channel id not configured.'];
        }

        $resolved = $this->resolveChannelId($theirChannelInput, $apiKey);

        if (! $resolved['ok']) {
            return $resolved;
        }

        try {
            $response = Http::timeout(15)->get(self::CHANNELS_URL, [
                'key' => $apiKey,
                'id' => $resolved['channelId'],
                'part' => 'brandingSettings',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $this->errorMessage($response)];
        }

        $featured = $response->json('items.0.brandingSettings.channel.featuredChannelsUrls', []);

        return [
            'ok' => true,
            'message' => 'ok',
            'isFeatured' => in_array($ourChannelId, $featured, true),
            'channelId' => $resolved['channelId'],
        ];
    }

    /**
     * @return array{ok: bool, message: string, isValid?: bool, videoId?: string, channelId?: string}
     */
    public function checkVideoProof(string $videoUrl, string $requiredKeyword): array
    {
        $apiKey = $this->settings->getYoutubeDataApiKey();

        if (! $apiKey) {
            return ['ok' => false, 'message' => 'YouTube API key not configured.'];
        }

        $videoId = $this->extractVideoId($videoUrl);

        if (! $videoId) {
            return ['ok' => false, 'message' => "That doesn't look like a valid YouTube video link."];
        }

        try {
            $response = Http::timeout(15)->get(self::VIDEOS_URL, [
                'key' => $apiKey,
                'id' => $videoId,
                'part' => 'snippet,status',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => $this->errorMessage($response)];
        }

        $item = $response->json('items.0');

        if (! $item) {
            return ['ok' => true, 'message' => 'ok', 'isValid' => false, 'videoId' => $videoId];
        }

        $isPublic = ($item['status']['privacyStatus'] ?? null) === 'public';
        $haystack = mb_strtolower(($item['snippet']['title'] ?? '').' '.($item['snippet']['description'] ?? ''));
        $hasKeyword = $requiredKeyword === '' || str_contains($haystack, mb_strtolower($requiredKeyword));

        return [
            'ok' => true,
            'message' => 'ok',
            'isValid' => $isPublic && $hasKeyword,
            'videoId' => $videoId,
            'channelId' => $item['snippet']['channelId'] ?? null,
        ];
    }

    /**
     * Accepts a raw channel id (UC...), an @handle (with or without the full youtube.com URL
     * around it), or a bare handle - resolves whichever form to a real channel id.
     *
     * @return array{ok: bool, message: string, channelId?: string}
     */
    private function resolveChannelId(string $input, string $apiKey): array
    {
        $value = trim($input);
        $value = preg_replace('#^https?://(www\.)?youtube\.com/#i', '', $value) ?? $value;
        $value = trim($value, '/ ');

        if (preg_match('/^UC[\w-]{22}$/', $value)) {
            return ['ok' => true, 'message' => 'ok', 'channelId' => $value];
        }

        $handle = ltrim(preg_replace('#^(channel/|c/|@)#i', '', $value) ?? $value, '@');

        if ($handle === '') {
            return ['ok' => false, 'message' => 'Please enter your channel handle or URL.'];
        }

        try {
            $response = Http::timeout(15)->get(self::CHANNELS_URL, [
                'key' => $apiKey,
                'forHandle' => '@'.$handle,
                'part' => 'id',
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        $channelId = $response->json('items.0.id');

        if (! $response->successful() || ! $channelId) {
            return ['ok' => false, 'message' => "Couldn't find a YouTube channel for that handle."];
        }

        return ['ok' => true, 'message' => 'ok', 'channelId' => $channelId];
    }

    /**
     * Handles the common URL shapes: youtu.be/ID, youtube.com/watch?v=ID, youtube.com/shorts/ID.
     */
    private function extractVideoId(string $url): ?string
    {
        $url = trim($url);

        if (preg_match('#youtu\.be/([\w-]{11})#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#[?&]v=([\w-]{11})#', $url, $m)) {
            return $m[1];
        }

        if (preg_match('#youtube\.com/shorts/([\w-]{11})#', $url, $m)) {
            return $m[1];
        }

        // A bare 11-character id, in case that's all the user pasted.
        if (preg_match('/^[\w-]{11}$/', $url)) {
            return $url;
        }

        return null;
    }

    private function errorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('error.message') ?? $response->body();

        return 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 300, '…');
    }
}
