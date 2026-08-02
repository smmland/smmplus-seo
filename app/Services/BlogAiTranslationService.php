<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

class BlogAiTranslationService
{
    // Controls the response format regardless of what the user's own configurable prompt says -
    // the prompt itself can be freely rewritten from Translation Settings, but parsing still
    // needs to be reliable, so the output contract is fixed here rather than left to the prompt.
    private const RESPONSE_CONTRACT = <<<'TEXT'
        You are translating a blog article for a website. Follow the instructions below, then
        respond with ONLY a single JSON object - no markdown code fences, no commentary before or
        after it - with exactly these keys:
        {
          "title": "translated on-page article title (plain text)",
          "seo_title": "translated <title> tag text",
          "meta_description": "translated meta description",
          "meta_keywords": "translated meta keywords",
          "og_title": "translated Open Graph title",
          "og_description": "translated Open Graph description",
          "twitter_title": "translated Twitter title",
          "twitter_description": "translated Twitter description",
          "content": "the translated article body as HTML - preserve the exact same tags, attributes, and CSS classes as the input, translate only the visible text"
        }
        Use an empty string for any field with no corresponding input to translate. Never
        translate HTML tag names, attribute names, class names, or URLs.
        TEXT;

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    /**
     * Translates one blog topic's title, SEO meta, and content into $targetLangCode using the
     * configured AI provider and the user's configurable prompt, then saves the result onto a
     * Url row for that language (creating one if it doesn't exist yet) using the same fields and
     * content-file layout manual extraction already produces, so the rest of the panel (preview,
     * copy tools, the visual editor) works on an AI translation exactly like an extracted one.
     *
     * @return array{ok: bool, message: string}
     */
    public function translate(Url $sourceRow, string $targetLangCode): array
    {
        // Best-effort only - many shared hosts disable this override, but it costs nothing to
        // try, and a single translation call (large content + AI generation time) can easily
        // exceed a default 30s PHP execution limit otherwise.
        @set_time_limit(170);

        $provider = $this->aiSettings->getProvider();
        $apiKey = $this->aiSettings->getApiKey($provider);

        if (! $apiKey) {
            return ['ok' => false, 'message' => "No API key saved for \"{$provider}\" - add one on the Translation Settings page first."];
        }

        if (! $sourceRow->content_extraction_path || ! Storage::disk('public')->exists($sourceRow->content_extraction_path)) {
            return ['ok' => false, 'message' => 'The source content hasn\'t been extracted yet - click "Extract content" on the default-language tab first.'];
        }

        $sourceContent = Storage::disk('public')->get($sourceRow->content_extraction_path);

        $targetLanguage = Language::query()->where('code', $targetLangCode)->value('name') ?? $targetLangCode;

        $prompt = $this->buildPrompt($sourceRow, $sourceContent, $targetLanguage);

        $result = match ($provider) {
            'claude' => $this->callClaude($apiKey, $this->aiSettings->getModel('claude'), $prompt),
            'chatgpt' => $this->callChatgpt($apiKey, $this->aiSettings->getModel('chatgpt'), $prompt),
            default => ['ok' => false, 'message' => "Unknown provider \"{$provider}\"."],
        };

        if (! $result['ok']) {
            return $result;
        }

        $parsed = $this->parseJsonResponse($result['text']);

        if (! $parsed || ! isset($parsed['content'])) {
            return ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Translation Settings.'];
        }

        $this->saveTranslation($sourceRow, $targetLangCode, $parsed);

        return ['ok' => true, 'message' => "Translated into {$targetLanguage} and saved."];
    }

    private function buildPrompt(Url $sourceRow, string $sourceContent, string $targetLanguage): string
    {
        $replacements = [
            '{{slug}}' => $sourceRow->slug ?? '',
            '{{title}}' => $sourceRow->article_title ?? '',
            '{{content}}' => $sourceContent,
            '{{meta_title}}' => $sourceRow->seo_title ?? '',
            '{{meta_description}}' => $sourceRow->meta_description ?? '',
            '{{meta_keywords}}' => $sourceRow->meta_keywords ?? '',
            '{{og_title}}' => $sourceRow->og_title ?? '',
            '{{og_description}}' => $sourceRow->og_description ?? '',
            '{{twitter_title}}' => $sourceRow->twitter_title ?? '',
            '{{twitter_description}}' => $sourceRow->twitter_description ?? '',
            '{{target_language}}' => $targetLanguage,
        ];

        $userPrompt = strtr($this->aiSettings->getBlogTranslationPrompt(), $replacements);

        return self::RESPONSE_CONTRACT."\n\n".$userPrompt;
    }

    /**
     * @return array{ok: bool, message?: string, text?: string}
     */
    private function callClaude(string $apiKey, string $model, string $prompt): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(170)->post('https://api.anthropic.com/v1/messages', [
                'model' => $model,
                'max_tokens' => 8192,
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'HTTP '.$response->status().': '.$this->errorSnippet($response->json('error.message') ?? $response->body())];
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            return ['ok' => false, 'message' => 'Claude returned an empty response.'];
        }

        return ['ok' => true, 'text' => $text];
    }

    /**
     * @return array{ok: bool, message?: string, text?: string}
     */
    private function callChatgpt(string $apiKey, string $model, string $prompt): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])->timeout(170)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $model,
                'max_tokens' => 8192,
                'response_format' => ['type' => 'json_object'],
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'HTTP '.$response->status().': '.$this->errorSnippet($response->json('error.message') ?? $response->body())];
        }

        $text = $response->json('choices.0.message.content');

        if (! $text) {
            return ['ok' => false, 'message' => 'ChatGPT returned an empty response.'];
        }

        return ['ok' => true, 'text' => $text];
    }

    private function errorSnippet(string $detail): string
    {
        return mb_strimwidth($detail, 0, 300, '…');
    }

    /**
     * @return ?array<string, string>
     */
    private function parseJsonResponse(string $text): ?array
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end < $start) {
            return null;
        }

        $decoded = json_decode(substr($text, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }

    private function saveTranslation(Url $sourceRow, string $targetLangCode, array $parsed): void
    {
        $scheme = parse_url($sourceRow->source_url, PHP_URL_SCHEME) ?: 'https';
        $host = parse_url($sourceRow->source_url, PHP_URL_HOST);
        $candidateUrl = "{$scheme}://{$host}/{$targetLangCode}/blog/{$sourceRow->slug}";
        $classified = app(UrlClassifierService::class)->classify($candidateUrl);

        $row = Url::query()->firstOrNew([
            'group_key' => $sourceRow->group_key,
            'lang' => $targetLangCode,
        ]);

        $isNew = ! $row->exists;

        $row->source_url = $candidateUrl;
        $row->path = $classified['path'];
        $row->pattern_type = $sourceRow->pattern_type;
        $row->slug = $sourceRow->slug;
        $row->is_active = true;
        $row->is_translated = true;
        $row->translation_checked_at = now();
        $row->article_title = $parsed['title'] ?? null;
        $row->seo_title = $parsed['seo_title'] ?? null;
        $row->meta_description = $parsed['meta_description'] ?? null;
        $row->meta_keywords = $parsed['meta_keywords'] ?? null;
        $row->og_title = $parsed['og_title'] ?? null;
        $row->og_description = $parsed['og_description'] ?? null;
        $row->twitter_title = $parsed['twitter_title'] ?? null;
        $row->twitter_description = $parsed['twitter_description'] ?? null;

        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->last_seen_at = now();

        $row->save();

        $baseDir = "blog/{$sourceRow->slug}";
        $content = $parsed['content'] ?? '';

        Storage::disk('public')->put("{$baseDir}/content-{$targetLangCode}.html", $content);

        $previewTitle = e($row->article_title ?? $row->slug);
        $dir = Language::direction($targetLangCode);
        $previewHtml = <<<HTML
            <!doctype html>
            <html lang="{$targetLangCode}" dir="{$dir}">
            <head>
            <meta charset="utf-8">
            <title>{$previewTitle} - preview</title>
            <script src="https://cdn.tailwindcss.com"></script>
            </head>
            <body class="mx-auto max-w-3xl px-6 py-10" dir="{$dir}">
            <h1 class="text-3xl font-bold mb-6">{$previewTitle}</h1>
            {$content}
            </body>
            </html>
            HTML;

        Storage::disk('public')->put("{$baseDir}/preview-{$targetLangCode}.html", $previewHtml);

        $row->content_extraction_path = "{$baseDir}/content-{$targetLangCode}.html";
        $row->content_extracted_at = now();
        $row->save();
    }
}
