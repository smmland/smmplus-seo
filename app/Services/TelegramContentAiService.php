<?php

namespace App\Services;

use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Generates the actual text for a Telegram post - two prompt shapes (blog summary, service
 * change announcement), same Http::pool/provider-branch pattern as
 * ServiceAiTranslationService::translateManyConcurrently(), just generalized to a single
 * "message" response field instead of translated content.
 */
class TelegramContentAiService
{
    // Documented for the Telegram Settings prompt editor - what {{token}} gets substituted with,
    // same convention as AiSettingsService::BLOG_TRANSLATION_PLACEHOLDERS etc.
    public const BLOG_SUMMARY_PLACEHOLDERS = [
        '{{title}}' => 'The article\'s title',
        '{{meta_description}}' => 'Meta description',
        '{{content}}' => 'The article body (plain text)',
        '{{url}}' => 'The article\'s live URL',
        '{{target_language}}' => 'The channel\'s language',
        '{{recent_posts}}' => 'Recent past posts to this channel, so the AI can avoid repeating the same wording/angle',
    ];

    public const SERVICE_ANNOUNCEMENT_PLACEHOLDERS = [
        '{{change_type}}' => '"added", "updated", or "removed"',
        '{{service_title}}' => 'The service\'s name',
        '{{category_title}}' => 'The service\'s category',
        '{{target_language}}' => 'The channel\'s language',
        '{{recent_posts}}' => 'Recent past posts to this channel, so the AI can avoid repeating the same wording/angle',
    ];

    private const RESPONSE_CONTRACT = <<<'TEXT'
        You are writing one Telegram channel post. Follow the instructions below, then respond
        with ONLY a single JSON object - no markdown code fences, no commentary before or after
        it - with exactly this key:
        {
          "message": "the Telegram post text"
        }
        TEXT;

    private const REQUEST_TIMEOUT_SECONDS = 60;

    public function __construct(
        private readonly AiSettingsService $aiSettings,
        private readonly TelegramSettingsService $telegramSettings,
    ) {}

    public function buildBlogSummaryPrompt(array $data, string $targetLanguage): string
    {
        $replacements = [
            '{{title}}' => $data['title'] ?? '',
            '{{meta_description}}' => $data['meta_description'] ?? '',
            '{{content}}' => $data['content'] ?? '',
            '{{url}}' => $data['url'] ?? '',
            '{{target_language}}' => $targetLanguage,
            '{{recent_posts}}' => $data['recent_posts'] ?? '(none yet)',
        ];

        $userPrompt = strtr($this->telegramSettings->getBlogSummaryPrompt(), $replacements);

        return self::RESPONSE_CONTRACT."\n\n".$userPrompt;
    }

    public function buildServiceAnnouncementPrompt(array $data, string $targetLanguage): string
    {
        $replacements = [
            '{{change_type}}' => $data['change_type'] ?? '',
            '{{service_title}}' => $data['service_title'] ?? '',
            '{{category_title}}' => $data['category_title'] ?? '',
            '{{target_language}}' => $targetLanguage,
            '{{recent_posts}}' => $data['recent_posts'] ?? '(none yet)',
        ];

        $userPrompt = strtr($this->telegramSettings->getServiceAnnouncementPrompt(), $replacements);

        return self::RESPONSE_CONTRACT."\n\n".$userPrompt;
    }

    /**
     * @param  Collection<int|string, array{prompt: string}>  $prepared  keyed by an arbitrary id
     * @return array<int|string, array{ok: bool, message: string, provider?: string, model?: string, input_tokens?: int, output_tokens?: int, estimated_cost_usd?: ?float}>
     */
    public function generateMany(Collection $prepared): array
    {
        $provider = $this->aiSettings->getProvider();
        $apiKey = $this->aiSettings->getApiKey($provider);
        $model = $this->aiSettings->getModel($provider);

        if (! $apiKey || ! $model) {
            $message = "No API key or model configured for \"{$provider}\" - set it up in AI Settings first.";

            return $prepared->mapWithKeys(fn ($p, $id) => [$id => ['ok' => false, 'message' => $message]])->all();
        }

        $responses = Http::pool(fn (Pool $pool) => $prepared->map(
            fn ($p, $id) => $provider === 'claude'
                ? $pool->as((string) $id)->withHeaders($this->claudeHeaders($apiKey))->timeout(self::REQUEST_TIMEOUT_SECONDS)->post('https://api.anthropic.com/v1/messages', $this->claudeRequestPayload($model, $p['prompt']))
                : $pool->as((string) $id)->withHeaders($this->chatgptHeaders($apiKey))->timeout(self::REQUEST_TIMEOUT_SECONDS)->post('https://api.openai.com/v1/chat/completions', $this->chatgptRequestPayload($model, $p['prompt']))
        )->all());

        $results = [];

        foreach ($prepared as $id => $p) {
            $response = $responses[(string) $id] ?? null;

            $extracted = $response instanceof Response
                ? ($provider === 'claude' ? $this->extractClaudeText($response) : $this->extractChatgptText($response))
                : ['ok' => false, 'message' => 'Connection error: '.($response instanceof \Throwable ? $response->getMessage() : 'the request failed.')];

            $usage = isset($extracted['inputTokens'])
                ? [
                    'provider' => $provider,
                    'model' => $model,
                    'input_tokens' => $extracted['inputTokens'],
                    'output_tokens' => $extracted['outputTokens'],
                    'estimated_cost_usd' => $this->aiSettings->estimateCost($model, $extracted['inputTokens'], $extracted['outputTokens']),
                ]
                : [];

            if (! $extracted['ok']) {
                $results[$id] = ['ok' => false, 'message' => $extracted['message'], ...$usage];

                continue;
            }

            $parsed = $this->parseJsonResponse($extracted['text']);

            if (! $parsed || ! isset($parsed['message']) || trim((string) $parsed['message']) === '') {
                $results[$id] = ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Telegram Settings.', ...$usage];

                continue;
            }

            $results[$id] = ['ok' => true, 'message' => trim((string) $parsed['message']), ...$usage];
        }

        return $results;
    }

    private function claudeHeaders(string $apiKey): array
    {
        return [
            'x-api-key' => $apiKey,
            'anthropic-version' => '2023-06-01',
        ];
    }

    private function claudeRequestPayload(string $model, string $prompt): array
    {
        return [
            'model' => $model,
            'max_tokens' => 1024,
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];
    }

    private function extractClaudeText(Response $response): array
    {
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'HTTP '.$response->status().': '.$this->errorSnippet($response->json('error.message') ?? $response->body())];
        }

        $usage = [
            'inputTokens' => (int) ($response->json('usage.input_tokens') ?? 0),
            'outputTokens' => (int) ($response->json('usage.output_tokens') ?? 0),
        ];

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode('');

        if ($text === '') {
            return ['ok' => false, 'message' => 'Claude returned an empty response.', ...$usage];
        }

        return ['ok' => true, 'text' => $text, ...$usage];
    }

    private function chatgptHeaders(string $apiKey): array
    {
        return [
            'Authorization' => 'Bearer '.$apiKey,
        ];
    }

    private function chatgptRequestPayload(string $model, string $prompt): array
    {
        $payload = [
            'model' => $model,
            'max_completion_tokens' => 2048,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        if ($this->isReasoningModel($model)) {
            $payload['reasoning_effort'] = 'low';
        }

        return $payload;
    }

    private function extractChatgptText(Response $response): array
    {
        if (! $response->successful()) {
            return ['ok' => false, 'message' => 'HTTP '.$response->status().': '.$this->errorSnippet($response->json('error.message') ?? $response->body())];
        }

        $usage = [
            'inputTokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
            'outputTokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
        ];

        $text = $response->json('choices.0.message.content');

        if (! $text) {
            $finishReason = $response->json('choices.0.finish_reason');
            $reasoningTokens = $response->json('usage.completion_tokens_details.reasoning_tokens');

            if ($finishReason === 'length' && $reasoningTokens) {
                return ['ok' => false, 'message' => "ChatGPT used its entire token budget on internal reasoning ({$reasoningTokens} tokens) and stopped before writing a reply. Try a non-reasoning model (e.g. GPT-4o) in AI Settings, which doesn't have this issue.", ...$usage];
            }

            return ['ok' => false, 'message' => 'ChatGPT returned an empty response'.($finishReason ? " (finish_reason: {$finishReason})" : '').'.', ...$usage];
        }

        return ['ok' => true, 'text' => $text, ...$usage];
    }

    private function isReasoningModel(string $model): bool
    {
        return (bool) preg_match('/^(o[0-9]|gpt-5)/', $model);
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
}
