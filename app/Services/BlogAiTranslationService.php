<?php

namespace App\Services;

use App\Models\Language;
use App\Models\Url;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    // A long article with a large token budget can legitimately take several minutes to
    // generate. This runs off a scheduled CLI command (ProcessBlogTranslationQueueCommand), not
    // a web request, so there's no short execution-time ceiling to fit under - the real limit is
    // that command's own ~850s time budget per batch, and this stays comfortably under it.
    private const REQUEST_TIMEOUT_SECONDS = 600;

    public function __construct(private readonly AiSettingsService $aiSettings) {}

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
     * Translates several already-queued jobs at once via concurrent outbound HTTP requests
     * (Http::pool) instead of one at a time - this is what "N concurrent translations" (AI
     * Settings) means in practice on a host with no persistent worker process to run several
     * of in parallel: N real requests in flight together within a single PHP process, using the
     * same provider/model/key for all of them since only one is configured system-wide.
     *
     * @param  Collection<int, \App\Models\BlogTranslationJob>  $jobs
     * @return array<int, array{ok: bool, message: string, provider?: string, model?: string, input_tokens?: int, output_tokens?: int, estimated_cost_usd?: ?float}> keyed by BlogTranslationJob id
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

        // Resolves each job's prompt up front so a job whose source content vanished since it
        // was queued (source row deleted, content never extracted) fails immediately with a
        // clear reason instead of being sent to the AI at all.
        $prepared = [];

        foreach ($jobs as $job) {
            $sourceRow = Url::query()->where('group_key', $job->group_key)->where('lang', $defaultLangCode)->first();

            if (! $sourceRow) {
                $prepared[$job->id] = ['error' => 'Could not find the default-language content for this topic.'];

                continue;
            }

            if (! $sourceRow->content_extraction_path || ! Storage::disk('public')->exists($sourceRow->content_extraction_path)) {
                $prepared[$job->id] = ['error' => 'The source content hasn\'t been extracted yet - click "Extract content" on the default-language tab first.'];

                continue;
            }

            $sourceContent = Storage::disk('public')->get($sourceRow->content_extraction_path);
            $targetLanguage = Language::query()->where('code', $job->target_lang)->value('name') ?? $job->target_lang;

            $prepared[$job->id] = [
                'sourceRow' => $sourceRow,
                'targetLangCode' => $job->target_lang,
                'targetLanguage' => $targetLanguage,
                'prompt' => $this->buildPrompt($sourceRow, $sourceContent, $targetLanguage),
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

                // A per-request transport failure (timeout, DNS, ...) inside a pool comes back
                // as the exception itself rather than a Response - and doesn't abort the other
                // requests in the pool, which is exactly the point of using one.
                $extracted = $response instanceof Response
                    ? ($provider === 'claude' ? $this->extractClaudeText($response) : $this->extractChatgptText($response))
                    : ['ok' => false, 'message' => 'Connection error: '.($response instanceof \Throwable ? $response->getMessage() : 'the request failed.')];

                // Present whenever the request actually reached the provider (even a failed one
                // that still burned tokens - e.g. the reasoning-budget-exhausted case) - absent
                // for a pure transport failure, where nothing was sent or billed.
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

                if (! $parsed || ! isset($parsed['content'])) {
                    $results[$jobId] = ['ok' => false, 'message' => 'The AI\'s reply could not be parsed as the expected JSON - try again, or check the prompt in Translation Settings.', ...$usage];

                    continue;
                }

                $this->saveTranslation($p['sourceRow'], $p['targetLangCode'], $parsed);
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
            'max_tokens' => 8192,
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

        // Captured on every successful HTTP response regardless of what's in it - a reply that
        // fails to parse, or an empty one, still burned real tokens and should still count
        // toward the cost dashboard.
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
            // OpenAI deprecated max_tokens for chat completions in favor of this - newer
            // models (o3, o4-mini, gpt-5, ...) reject max_tokens outright with HTTP 400.
            // Reasoning models also spend part of this same budget on hidden "reasoning
            // tokens" before writing anything visible, so this needs real headroom above
            // the translated article's expected size or those models come back empty -
            // 32768 wasn't enough for longer articles even with reasoning_effort=low below,
            // so this is set close to the shared ceiling across the whole reasoning family
            // (o3/o4-mini and gpt-5/gpt-5-mini all support at least 100k output tokens).
            'max_completion_tokens' => 100000,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'user', 'content' => $prompt],
            ],
        ];

        // A straightforward translate-and-reformat task never needs deep reasoning - without
        // this, a reasoning model can burn its *entire* token budget thinking and stop before
        // writing any visible reply at all (a raised budget alone doesn't fix that, since the
        // model can just reason more too). Only sent for models that actually support it -
        // sending it to a non-reasoning model like gpt-4o fails the same way max_tokens used to.
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

        // Captured on every successful HTTP response regardless of what's in it - a reasoning
        // model burning its whole budget on hidden reasoning still consumed (and gets billed
        // for) every one of those tokens, even though extraction below fails for that reply.
        $usage = [
            'inputTokens' => (int) ($response->json('usage.prompt_tokens') ?? 0),
            'outputTokens' => (int) ($response->json('usage.completion_tokens') ?? 0),
        ];

        $text = $response->json('choices.0.message.content');

        if (! $text) {
            $finishReason = $response->json('choices.0.finish_reason');
            $reasoningTokens = $response->json('usage.completion_tokens_details.reasoning_tokens');

            // The unhelpful default: a reasoning model (o3, o4-mini, gpt-5, ...) can spend its
            // entire token budget on hidden reasoning and stop before writing any visible reply -
            // the API call still succeeds, just with nothing in it. Surface that distinctly from
            // a genuinely empty reply so it's obvious a bigger budget (or a non-reasoning model
            // like GPT-4o) is what's needed, not a prompt fix.
            if ($finishReason === 'length' && $reasoningTokens) {
                return ['ok' => false, 'message' => "ChatGPT used its entire token budget on internal reasoning ({$reasoningTokens} tokens) and stopped before writing a reply. Try a non-reasoning model (e.g. GPT-4o) in AI Settings, which doesn't have this issue.", ...$usage];
            }

            return ['ok' => false, 'message' => 'ChatGPT returned an empty response'.($finishReason ? " (finish_reason: {$finishReason})" : '').'.', ...$usage];
        }

        return ['ok' => true, 'text' => $text, ...$usage];
    }

    // Matches OpenAI's reasoning-capable chat completions models - the o-series and gpt-5 family
    // (o1, o3, o4-mini, gpt-5, gpt-5-mini, ...). Everything else (gpt-4o, gpt-4.1, ...) rejects
    // reasoning_effort as an unsupported parameter, the same way it rejects max_tokens.
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
        // Exempts this row from SyncService's "not in the sitemap -> deactivate" pruning
        // permanently, regardless of confirmation status - see the migration that added this
        // column for why that matters.
        if (Schema::hasColumn('urls', 'is_ai_guessed')) {
            $row->is_ai_guessed = true;
        }
        $row->is_translated = true;
        // Deliberately NOT touching translation_checked_at here - that field means "confirmed
        // live via BlogTranslationDetectionService's own fetch", not "we generated content for
        // it". Leaving it at whatever it was (null for a first translation) is what makes a
        // freshly-translated row correctly show as pending a real live-site confirmation
        // (BlogTranslationQueue::needsSiteUpdate()) instead of looking confirmed the moment
        // content is generated here - and as a side effect, makes it overdue for the next
        // detection cron run (BlogTranslationDetectionService::dueQuery() prioritizes null).

        // A manual "needs update" override (BlogTranslationQueue::toggleSiteUpdateOverride())
        // exists to correct a stale/wrong verdict for the content that was there before - fresh
        // content from this translation supersedes whatever that was about, and the null
        // translation_checked_at above already makes it show as needing a site update on its
        // own, so there's nothing for the override to add here.
        if (Schema::hasColumn('urls', 'site_update_override')) {
            $row->site_update_override = null;
        }

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
