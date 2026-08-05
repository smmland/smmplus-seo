<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Generates an image for a Telegram post that has no natural photo of its own (service catalog
 * change announcements - see TelegramPostGeneratorService::draftServiceChanges()). Always uses
 * OpenAI's Images API regardless of which provider is configured as the active *text* one in AI
 * Settings (AiSettingsService::getProvider()) - Claude has no image generation API - so this
 * reads getApiKey('chatgpt') directly rather than going through getProvider().
 */
class TelegramImageAiService
{
    private const REQUEST_TIMEOUT_SECONDS = 90;

    private const IMAGE_DIR = 'telegram/images';

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    /**
     * @return array{ok: bool, path?: string, message: string}
     */
    public function generate(string $prompt): array
    {
        $apiKey = $this->aiSettings->getApiKey('chatgpt');
        $model = $this->aiSettings->getImageModel();

        if (! $apiKey) {
            return ['ok' => false, 'message' => 'No OpenAI API key configured - image generation needs one even if Claude is the active provider for text. Add it in AI Settings.'];
        }

        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer '.$apiKey])
                ->timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model' => $model,
                    'prompt' => $prompt,
                    'size' => '1024x1024',
                    'n' => 1,
                ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            $detail = $response->json('error.message') ?? $response->body();

            return ['ok' => false, 'message' => 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 300, '…')];
        }

        $b64 = $response->json('data.0.b64_json');
        $url = $response->json('data.0.url');

        if ($b64) {
            $bytes = base64_decode($b64, true);
        } elseif ($url) {
            // Some models (dall-e-2/3) return a URL instead of inline base64 - fetch it once,
            // right away, since OpenAI's image URLs expire after about an hour.
            try {
                $imageResponse = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)->get($url);
            } catch (\Throwable $e) {
                return ['ok' => false, 'message' => 'Image generated, but downloading it failed: '.$e->getMessage()];
            }

            if (! $imageResponse->successful()) {
                return ['ok' => false, 'message' => 'Image generated, but downloading it failed with HTTP '.$imageResponse->status().'.'];
            }

            $bytes = $imageResponse->body();
        } else {
            return ['ok' => false, 'message' => 'OpenAI\'s response had no image data.'];
        }

        if (! $bytes) {
            return ['ok' => false, 'message' => 'Could not decode the generated image.'];
        }

        $path = self::IMAGE_DIR.'/'.Str::uuid()->toString().'.png';
        Storage::disk('public')->put($path, $bytes);

        return ['ok' => true, 'path' => $path, 'message' => 'Image generated.'];
    }
}
