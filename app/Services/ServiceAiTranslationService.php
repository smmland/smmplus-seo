<?php

namespace App\Services;

use App\Models\Language;
use App\Models\ServiceTranslation;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ServiceAiTranslationService
{
    // Only "description" - unlike blog articles, title/category translation isn't needed yet
    // (see AiSettingsService::SERVICE_TRANSLATION_PLACEHOLDERS), so the contract stays minimal.
    private const RESPONSE_CONTRACT = <<<'TEXT'
        You are translating one e-commerce service's description for a website. Follow the
        instructions below, then respond with ONLY a single JSON object - no markdown code
        fences, no commentary before or after it - with exactly this key:
        {
          "description": "the translated description as HTML - preserve <br> tags exactly, translate only the visible text"
        }
        Never translate HTML tag names or attributes.
        TEXT;

    // A short description translates fast - no need for blog's 600s ceiling, but this still
    // rides the same scheduled-command execution model (no persistent worker), so a generous
    // margin over a normal response time is kept rather than trimming it aggressively.
    private const REQUEST_TIMEOUT_SECONDS = 120;

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    private function buildPrompt(ServiceTranslation $sourceRow, string $targetLanguage): string
    {
        $replacements = [
            '{{service_title}}' => $sourceRow->title ?? '',
            '{{category_title}}' => $sourceRow->category_title ?? '',
            '{{description}}' => $sourceRow->description ?? '',
            '{{target_language}}' => $targetLanguage,
        ];

        $userPrompt = strtr($this->aiSettings->getServiceTranslationPrompt(), $replacements);

        return self::RESPONSE_CONTRACT."\n\n".$userPrompt;
    }

    /**
     * Same Http::pool concurrency approach as BlogAiTranslationService::translateManyConcurrently
     * - N real requests in flight together within a single PHP process, since this host has no
     * persistent worker process to run several of in parallel.
     *
     * @param  Collection<int, \App\Models\ServiceTranslationJob>  $jobs
     * @return array<int, array{ok: bool, message: string, provider?: string, model?: string, input_tokens?: int, output_tokens?: int, estimated_cost_usd?: ?float}> keyed by ServiceTranslationJob id
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
            $sourceRow = ServiceTranslation::query()
                ->where('service_key', $job->service_key)
                ->where('lang', $defaultLangCode)
                ->first();

            if (! $sourceRow) {
                $prepared[$job->id] = ['error' => 'Could not find the default-language description for this service.'];

                continue;
            }

            if (blank($sourceRow->description)) {
                $prepared[$job->id] = ['error' => 'This service has no description to translate.'];

                continue;
            }

            $targetLanguage = Language::query()->where('code', $job->target_lang)->value('name') ?? $job->target_lang;

            $prepared[$job->id] = [
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

                if (! $parsed || ! isset($parsed['description']) || trim((string) $parsed['description']) === '') {
                    $results[$jobId] = ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Translation Settings.', ...$usage];

                    continue;
                }

                $this->saveTranslation($p['sourceRow'], $p['targetLangCode'], $parsed['description']);
                $results[$jobId] = ['ok' => true, 'message' => "Translated into {$p['targetLanguage']} and saved.", ...$usage];
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
            'max_tokens' => 2048,
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
            'max_completion_tokens' => 4096,
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

    private function saveTranslation(ServiceTranslation $sourceRow, string $targetLangCode, string $translatedDescription): void
    {
        $row = ServiceTranslation::query()->firstOrNew([
            'service_key' => $sourceRow->service_key,
            'lang' => $targetLangCode,
        ]);

        $isNew = ! $row->exists;

        $row->category_id = $sourceRow->category_id;
        $row->category_title = $sourceRow->category_title;
        // Title isn't translated yet (see AiSettingsService::SERVICE_TRANSLATION_PLACEHOLDERS) -
        // carries the default-language title over as a readable placeholder rather than leaving
        // it blank, since the queue page needs something to label the row with.
        $row->title = $row->title ?: $sourceRow->title;
        $row->description = $translatedDescription;
        $row->description_text = trim(preg_replace('/\s+/', ' ', strip_tags($translatedDescription)));
        $row->is_translated = true;
        // Not checked_at - that's reserved for ServiceCatalogService::refreshLanguage()'s live
        // page checks. translated_at marks when we saved this, separately from live_confirmed_at
        // (untouched here), so needsSiteUpdate() can tell "translated, not uploaded yet" apart
        // from "confirmed live" until the next refresh actually verifies it against the site.
        $row->translated_at = now();
        $row->check_note = 'Translated by AI - not yet confirmed live on the site.';

        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->last_seen_at = now();

        $row->save();
    }
}
