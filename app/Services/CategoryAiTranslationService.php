<?php

namespace App\Services;

use App\Models\CategoryTranslation;
use App\Models\CategoryTranslationJob;
use App\Models\Language;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * The category counterpart to ServiceAiTranslationService's title-only path - same shape (prompt
 * building, Http::pool concurrent dispatch, provider branching, JSON response contract), just
 * against CategoryTranslation/CategoryTranslationJob instead of ServiceTranslation. Kept as its
 * own class rather than folded into ServiceAiTranslationService since a category doesn't belong
 * to a service_key, and this codebase's established convention is a separate, fully independent
 * pipeline per translatable concern (see ServiceTranslationQueue's own class doc).
 */
class CategoryAiTranslationService
{
    private const RESPONSE_CONTRACT = <<<'TEXT'
        You are translating one e-commerce category name for a website. Follow the instructions
        below, then respond with ONLY a single JSON object - no markdown code fences, no
        commentary before or after it - with exactly this key:
        {
          "title": "the translated category name as plain text - short, like a section heading, not a full sentence"
        }
        TEXT;

    private const REQUEST_TIMEOUT_SECONDS = 120;

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    private function buildPrompt(CategoryTranslation $sourceRow, string $targetLanguage): string
    {
        $replacements = [
            '{{category_title}}' => $sourceRow->title ?? '',
            '{{target_language}}' => $targetLanguage,
        ];

        $userPrompt = strtr($this->aiSettings->getCategoryTranslationPrompt(), $replacements);

        return self::RESPONSE_CONTRACT."\n\n".$userPrompt;
    }

    /**
     * @param  Collection<int, CategoryTranslationJob>  $jobs
     * @return array<int, array{ok: bool, message: string, provider?: string, model?: string, input_tokens?: int, output_tokens?: int, estimated_cost_usd?: ?float}> keyed by CategoryTranslationJob id
     */
    public function translateManyConcurrently(Collection $jobs): array
    {
        $provider = $this->aiSettings->getProvider();
        $apiKey = $this->aiSettings->getApiKey($provider);
        $model = $this->aiSettings->getModel($provider);

        if (! $apiKey || ! $model) {
            $message = "No API key or model configured for \"{$provider}\" - set it up in AI Settings first.";

            return $jobs->mapWithKeys(fn ($job) => [$job->id => ['ok' => false, 'message' => $message]])->all();
        }

        $defaultLangCode = Language::query()->where('is_default', true)->value('code') ?? 'en';

        $prepared = [];

        foreach ($jobs as $job) {
            $sourceRow = CategoryTranslation::query()
                ->where('category_id', $job->category_id)
                ->where('lang', $defaultLangCode)
                ->first();

            if (! $sourceRow) {
                $prepared[$job->id] = ['error' => 'Could not find the default-language name for this category.'];

                continue;
            }

            if (blank($sourceRow->title)) {
                $prepared[$job->id] = ['error' => 'This category has no name to translate.'];

                continue;
            }

            $targetLanguage = Language::query()->where('code', $job->target_lang)->value('name') ?? $job->target_lang;

            $prepared[$job->id] = [
                'trigger' => $job->trigger,
                'sourceRow' => $sourceRow,
                'targetLangCode' => $job->target_lang,
                'targetLanguage' => $targetLanguage,
                'prompt' => $this->buildPrompt($sourceRow, $targetLanguage),
            ];
        }

        $toSend = collect($prepared)->filter(fn ($p) => isset($p['prompt']));

        $results = [];

        if ($toSend->isNotEmpty()) {
            $responses = Http::pool(fn (Pool $pool) => $toSend->map(
                fn ($p, $jobId) => $provider === 'claude'
                    ? $pool->as((string) $jobId)->withHeaders($this->claudeHeaders($apiKey))->timeout(self::REQUEST_TIMEOUT_SECONDS)->post('https://api.anthropic.com/v1/messages', $this->claudeRequestPayload($model, $p['prompt']))
                    : $pool->as((string) $jobId)->withHeaders($this->chatgptHeaders($apiKey))->timeout(self::REQUEST_TIMEOUT_SECONDS)->post('https://api.openai.com/v1/chat/completions', $this->chatgptRequestPayload($model, $p['prompt']))
            )->all());

            foreach ($toSend as $jobId => $p) {
                $response = $responses[(string) $jobId] ?? null;

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
                    $results[$jobId] = ['ok' => false, 'message' => $extracted['message'], ...$usage];

                    continue;
                }

                $parsed = $this->parseJsonResponse($extracted['text']);

                if (! $parsed || ! isset($parsed['title']) || trim((string) $parsed['title']) === '') {
                    $results[$jobId] = ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Translation Settings.', ...$usage];

                    continue;
                }

                $isRetranslation = $p['trigger'] === CategoryTranslationJob::TRIGGER_SOURCE_CHANGED;

                $this->saveTranslation($p['sourceRow'], $p['targetLangCode'], $parsed['title'], $isRetranslation);

                $results[$jobId] = ['ok' => true, 'message' => 'Translated into '.$p['targetLanguage'].' and saved.', ...$usage];
            }
        }

        foreach ($prepared as $jobId => $p) {
            if (! isset($results[$jobId])) {
                $results[$jobId] = ['ok' => false, 'message' => $p['error']];
            }
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

    private function saveTranslation(CategoryTranslation $sourceRow, string $targetLangCode, string $translatedTitle, bool $isRetranslation = false): void
    {
        $row = CategoryTranslation::query()->firstOrNew([
            'category_id' => $sourceRow->category_id,
            'lang' => $targetLangCode,
        ]);

        $isNew = ! $row->exists;

        $row->title = trim(preg_replace('/\s+/', ' ', $translatedTitle));
        $row->is_translated = true;
        $row->translated_at = now();
        $row->title_translated_from_hash = $sourceRow->source_title_hash;

        if ($isRetranslation) {
            $row->auto_retranslated_at = now();
            $row->check_note = 'Automatically re-translated by AI - the source name changed. Not yet confirmed live on the site.';
        } else {
            $row->check_note = 'Translated by AI - not yet confirmed live on the site.';
        }

        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->last_seen_at = now();

        $row->save();
    }
}
