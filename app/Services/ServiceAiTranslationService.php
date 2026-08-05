<?php

namespace App\Services;

use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\ServiceTranslationJob;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

class ServiceAiTranslationService
{
    private const RESPONSE_CONTRACT_DESCRIPTION = <<<'TEXT'
        You are translating one e-commerce service's description for a website. Follow the
        instructions below, then respond with ONLY a single JSON object - no markdown code
        fences, no commentary before or after it - with exactly this key:
        {
          "description": "the translated description as HTML - preserve <br> tags exactly, translate only the visible text"
        }
        Never translate HTML tag names or attributes.
        TEXT;

    // Title translation is a separate, much smaller contract - see
    // AiSettingsService::SERVICE_TITLE_TRANSLATION_PLACEHOLDERS for why this is its own prompt
    // rather than folded into the description one.
    private const RESPONSE_CONTRACT_TITLE = <<<'TEXT'
        You are translating one e-commerce service's title/name for a website. Follow the
        instructions below, then respond with ONLY a single JSON object - no markdown code
        fences, no commentary before or after it - with exactly this key:
        {
          "title": "the translated title as plain text - short, like a product name, not a full sentence"
        }
        TEXT;

    // A short description/title translates fast - no need for blog's 600s ceiling, but this
    // still rides the same scheduled-command execution model (no persistent worker), so a
    // generous margin over a normal response time is kept rather than trimming it aggressively.
    private const REQUEST_TIMEOUT_SECONDS = 120;

    public function __construct(private readonly AiSettingsService $aiSettings) {}

    private function buildDescriptionPrompt(ServiceTranslation $sourceRow, string $targetLanguage): string
    {
        $replacements = [
            '{{service_title}}' => $sourceRow->title ?? '',
            '{{category_title}}' => $sourceRow->category_title ?? '',
            '{{description}}' => $sourceRow->description ?? '',
            '{{target_language}}' => $targetLanguage,
        ];

        $userPrompt = strtr($this->aiSettings->getServiceTranslationPrompt(), $replacements);

        return self::RESPONSE_CONTRACT_DESCRIPTION."\n\n".$userPrompt;
    }

    private function buildTitlePrompt(ServiceTranslation $sourceRow, string $targetLanguage): string
    {
        $replacements = [
            '{{service_title}}' => $sourceRow->title ?? '',
            '{{category_title}}' => $sourceRow->category_title ?? '',
            '{{target_language}}' => $targetLanguage,
        ];

        $userPrompt = strtr($this->aiSettings->getServiceTitleTranslationPrompt(), $replacements);

        return self::RESPONSE_CONTRACT_TITLE."\n\n".$userPrompt;
    }

    /**
     * Same Http::pool concurrency approach as BlogAiTranslationService::translateManyConcurrently
     * - N real requests in flight together within a single PHP process, since this host has no
     * persistent worker process to run several of in parallel. Handles both description and
     * title jobs in the same batch (branching per-job on $job->field) since they share the same
     * queue/provider/concurrency setting, even though each field's prompt, response contract,
     * and saved columns are entirely separate.
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
            $isTitle = $job->field === ServiceTranslationJob::FIELD_TITLE;

            $sourceRow = ServiceTranslation::query()
                ->where('service_key', $job->service_key)
                ->where('lang', $defaultLangCode)
                ->first();

            if (! $sourceRow) {
                $prepared[$job->id] = ['error' => 'Could not find the default-language '.($isTitle ? 'title' : 'description').' for this service.'];

                continue;
            }

            if ($isTitle ? blank($sourceRow->title) : blank($sourceRow->description)) {
                $prepared[$job->id] = ['error' => 'This service has no '.($isTitle ? 'title' : 'description').' to translate.'];

                continue;
            }

            $targetLanguage = Language::query()->where('code', $job->target_lang)->value('name') ?? $job->target_lang;

            $prepared[$job->id] = [
                'field' => $job->field,
                'trigger' => $job->trigger,
                'sourceRow' => $sourceRow,
                'targetLangCode' => $job->target_lang,
                'targetLanguage' => $targetLanguage,
                'prompt' => $isTitle
                    ? $this->buildTitlePrompt($sourceRow, $targetLanguage)
                    : $this->buildDescriptionPrompt($sourceRow, $targetLanguage),
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
                $isTitle = $p['field'] === ServiceTranslationJob::FIELD_TITLE;

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
                $key = $isTitle ? 'title' : 'description';

                if (! $parsed || ! isset($parsed[$key]) || trim((string) $parsed[$key]) === '') {
                    $results[$jobId] = ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Translation Settings.', ...$usage];

                    continue;
                }

                $isRetranslation = $p['trigger'] === ServiceTranslationJob::TRIGGER_SOURCE_CHANGED;

                if ($isTitle) {
                    $this->saveTitleTranslation($p['sourceRow'], $p['targetLangCode'], $parsed['title'], $isRetranslation);
                } else {
                    $this->saveDescriptionTranslation($p['sourceRow'], $p['targetLangCode'], $parsed['description'], $isRetranslation);
                }

                $results[$jobId] = ['ok' => true, 'message' => ($isTitle ? 'Title translated' : 'Translated').' into '.$p['targetLanguage'].' and saved.', ...$usage];
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

    private function saveDescriptionTranslation(ServiceTranslation $sourceRow, string $targetLangCode, string $translatedDescription, bool $isRetranslation = false): void
    {
        $row = ServiceTranslation::query()->firstOrNew([
            'service_key' => $sourceRow->service_key,
            'lang' => $targetLangCode,
        ]);

        $isNew = ! $row->exists;

        $row->category_id = $sourceRow->category_id;
        $row->category_title = $sourceRow->category_title;
        // Only touched if this row has no title of its own at all yet - a title translation job
        // (saveTitleTranslation() below) may already have set a real one, which this must never
        // clobber with the default-language placeholder.
        $row->title = $row->title ?: $sourceRow->title;
        $row->description = $translatedDescription;
        $row->description_text = trim(preg_replace('/\s+/', ' ', strip_tags($translatedDescription)));
        $row->is_translated = true;
        // Not checked_at - that's reserved for ServiceCatalogService::refreshLanguage()'s live
        // page checks. translated_at marks when we saved this, separately from live_confirmed_at
        // (untouched here), so needsSiteUpdate() can tell "translated, not uploaded yet" apart
        // from "confirmed live" until the next refresh actually verifies it against the site.
        $row->translated_at = now();
        // What the default description's own hash was at the moment of this translation -
        // refreshLanguage() compares this against the default row's *current* hash to tell a
        // still-fresh translation apart from one made obsolete by a later source edit.
        $row->description_translated_from_hash = $sourceRow->source_description_hash;

        if ($isRetranslation) {
            // Queued by RefreshServiceCatalogCommand because the default-language description
            // changed since this row's last translation - marked distinctly (queue()'s badges,
            // the details popup) so it reads as "just automatically re-translated", not just
            // "translated", the moment the admin next opens the page.
            $row->description_auto_retranslated_at = now();
            $row->check_note = 'Automatically re-translated by AI - the source description changed. Not yet confirmed live on the site.';
        } else {
            $row->check_note = 'Translated by AI - not yet confirmed live on the site.';
        }

        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->last_seen_at = now();

        $row->save();
    }

    private function saveTitleTranslation(ServiceTranslation $sourceRow, string $targetLangCode, string $translatedTitle, bool $isRetranslation = false): void
    {
        $row = ServiceTranslation::query()->firstOrNew([
            'service_key' => $sourceRow->service_key,
            'lang' => $targetLangCode,
        ]);

        $isNew = ! $row->exists;

        $row->category_id = $sourceRow->category_id;
        $row->category_title = $sourceRow->category_title;
        $row->title = trim(preg_replace('/\s+/', ' ', $translatedTitle));
        $row->is_title_translated = true;
        // Same reasoning as translated_at on the description side - kept fully separate so
        // titleNeedsSiteUpdate() can track this independently of the description's own state.
        $row->title_translated_at = now();
        // The title counterpart to description_translated_from_hash above.
        $row->title_translated_from_hash = $sourceRow->source_title_hash;

        if ($isRetranslation) {
            $row->title_auto_retranslated_at = now();
            $row->title_check_note = 'Automatically re-translated by AI - the source title changed. Not yet confirmed live on the site.';
        } else {
            $row->title_check_note = 'Translated by AI - not yet confirmed live on the site.';
        }

        if ($isNew) {
            $row->first_seen_at = now();
        }
        $row->last_seen_at = now();

        $row->save();
    }
}
