<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class AiSettingsService
{
    public const PROVIDERS = ['claude', 'chatgpt'];

    public const PROVIDER_LABELS = [
        'claude' => 'Claude (Anthropic)',
        'chatgpt' => 'ChatGPT (OpenAI)',
    ];

    // USD per 1M tokens, approximate published list pricing - used only to estimate spend on the
    // cost dashboard, not billed anywhere. A model not listed here (a custom/newer model id) has
    // no known rate, so its jobs show token counts but no dollar estimate rather than guessing.
    public const MODEL_PRICING_PER_MILLION_TOKENS = [
        'claude-sonnet-4-5-20250929' => ['input' => 3.00, 'output' => 15.00],
        'claude-sonnet-5' => ['input' => 3.00, 'output' => 15.00],
        'claude-opus-5' => ['input' => 15.00, 'output' => 75.00],
        'claude-fable-5' => ['input' => 0.80, 'output' => 4.00],
        'claude-haiku-4-5-20251001' => ['input' => 0.80, 'output' => 4.00],
        'gpt-4o' => ['input' => 2.50, 'output' => 10.00],
        'gpt-4o-mini' => ['input' => 0.15, 'output' => 0.60],
        'gpt-4.1' => ['input' => 2.00, 'output' => 8.00],
        'gpt-4.1-mini' => ['input' => 0.40, 'output' => 1.60],
        'gpt-5' => ['input' => 2.50, 'output' => 10.00],
        'o3' => ['input' => 2.00, 'output' => 8.00],
        'o4-mini' => ['input' => 1.10, 'output' => 4.40],
    ];

    // Documented for the prompt editor's UI - what {{token}} gets substituted with, once the
    // actual translate call is wired up in a later phase. Exact-string substitution only, so
    // prose in the prompt that merely *looks* like a token (e.g. a Twig "{{ post['content'] }}"
    // snippet used as descriptive text) is never accidentally matched.
    public const BLOG_TRANSLATION_PLACEHOLDERS = [
        '{{slug}}' => 'The topic\'s URL slug',
        '{{title}}' => 'The on-page article title (H1)',
        '{{content}}' => 'The extracted article body HTML to translate',
        '{{meta_title}}' => 'The <title> tag text',
        '{{meta_description}}' => 'Meta description',
        '{{meta_keywords}}' => 'Meta keywords',
        '{{og_title}}' => 'Open Graph title',
        '{{og_description}}' => 'Open Graph description',
        '{{twitter_title}}' => 'Twitter title',
        '{{twitter_description}}' => 'Twitter description',
        '{{target_language}}' => 'The language being translated into',
    ];

    private const KEY_PROVIDER = 'ai_provider';
    private const KEY_API_KEY_PREFIX = 'ai_api_key_';
    private const KEY_MODEL_PREFIX = 'ai_model_';
    private const KEY_BLOG_TRANSLATION_PROMPT = 'ai_prompt_blog_translation';
    private const KEY_MAX_CONCURRENT_TRANSLATIONS = 'ai_max_concurrent_translations';

    private const DEFAULT_PROVIDER = 'claude';
    private const DEFAULT_MAX_CONCURRENT_TRANSLATIONS = 3;

    // Purely a safety rail against a typo'd huge number firing that many simultaneous requests
    // at once - the provider's own rate limits are the real ceiling, this just keeps a mistake
    // from being catastrophic.
    private const MAX_CONCURRENT_TRANSLATIONS_CEILING = 10;

    // Editable on the settings page rather than hardcoded permanently - API providers retire
    // model ids over time, so these are just sane starting points, not guaranteed forever.
    private const DEFAULT_MODELS = [
        'claude' => 'claude-sonnet-4-5-20250929',
        'chatgpt' => 'gpt-4o',
    ];

    // Replaces an earlier placeholder ported verbatim from smmplus-extensions' old git/repo
    // workflow (create files, commit, push) that told the model to translate into 17 hardcoded
    // languages at once and never referenced {{target_language}} anywhere - the model had no
    // reliable signal for which single language the caller actually wanted, and would default to
    // whichever one it picked from that list (observed: asking for fa returned ru). This version
    // is built for the single-JSON-object contract this service actually parses (prepended
    // separately as RESPONSE_CONTRACT) and names the target language up front and repeatedly.
    private const DEFAULT_BLOG_TRANSLATION_PROMPT = <<<'PROMPT'
        Translate the blog article below from its original language into {{target_language}} -
        and only {{target_language}}. Do not translate into any other language, and do not leave
        any sentence, word, or fragment untranslated in the original language.

        Translate like a native, professional {{target_language}}-speaking copywriter would
        rewrite this article for a {{target_language}}-speaking audience - not a literal,
        word-for-word translation. Keep the same meaning, tone, and level of detail, but let
        phrasing, idioms, and sentence structure read naturally in {{target_language}}.

        Rules:
        - Preserve the HTML exactly: every tag, attribute, and class name stays as-is. Translate
          only the visible text content.
        - Never translate HTML tag names, attribute names, class names, or URLs.
        - Keep numbers, code snippets, and brand/product names unchanged unless a localized form
          of the brand name is already standard in {{target_language}}.
        - Match the article's existing heading structure and paragraph breaks - don't merge,
          split, or reorder sections.
        - Keep the translated Meta description to 300 characters or fewer - rephrase or trim it
          if a direct translation would run longer, without cutting off mid-sentence.

        ## Article title
        {{title}}

        ## Article body (HTML)
        {{content}}

        ## SEO metadata to translate into {{target_language}} as well
        <title> tag: {{meta_title}}
        Meta description: {{meta_description}}
        Meta keywords: {{meta_keywords}}
        Open Graph title: {{og_title}}
        Open Graph description: {{og_description}}
        Twitter title: {{twitter_title}}
        Twitter description: {{twitter_description}}
        PROMPT;

    public function getProvider(): string
    {
        return $this->get(self::KEY_PROVIDER) ?? self::DEFAULT_PROVIDER;
    }

    public function setProvider(string $provider): void
    {
        $this->set(self::KEY_PROVIDER, $provider);
    }

    public function hasApiKey(string $provider): bool
    {
        return $this->get(self::apiKeyStorageKey($provider)) !== null;
    }

    public function getApiKey(string $provider): ?string
    {
        $encrypted = $this->get(self::apiKeyStorageKey($provider));

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            // Stored value isn't decryptable (e.g. APP_KEY rotated since it was saved) - treat
            // it the same as "no key set" rather than fatally erroring the settings page.
            return null;
        }
    }

    // Blank/null leaves the currently-stored key untouched - the form field is never
    // pre-filled with the real secret, so "didn't type anything" must mean "keep it", not
    // "clear it". Use clearApiKey() to actually remove one.
    public function setApiKey(string $provider, ?string $key): void
    {
        if ($key === null || $key === '') {
            return;
        }

        $this->set(self::apiKeyStorageKey($provider), Crypt::encryptString($key));
    }

    public function clearApiKey(string $provider): void
    {
        Setting::query()->where('key', self::apiKeyStorageKey($provider))->delete();
    }

    public function getModel(string $provider): string
    {
        return $this->get(self::modelStorageKey($provider)) ?? (self::DEFAULT_MODELS[$provider] ?? '');
    }

    public function setModel(string $provider, ?string $model): void
    {
        if ($model !== null && $model !== '') {
            $this->set(self::modelStorageKey($provider), $model);
        }
    }

    private static function modelStorageKey(string $provider): string
    {
        return self::KEY_MODEL_PREFIX.$provider;
    }

    public function getBlogTranslationPrompt(): string
    {
        return $this->get(self::KEY_BLOG_TRANSLATION_PROMPT) ?? self::DEFAULT_BLOG_TRANSLATION_PROMPT;
    }

    public function setBlogTranslationPrompt(string $prompt): void
    {
        $this->set(self::KEY_BLOG_TRANSLATION_PROMPT, $prompt);
    }

    public function defaultBlogTranslationPrompt(): string
    {
        return self::DEFAULT_BLOG_TRANSLATION_PROMPT;
    }

    /**
     * How many translation requests the queue processor (translation:process-queue) fires at
     * the AI provider at once, instead of one at a time - this host has no persistent worker
     * process to run several of in parallel, so "concurrent" here means N outbound HTTP requests
     * in flight together within a single PHP process (Http::pool), not N separate processes.
     */
    public function getMaxConcurrentTranslations(): int
    {
        $stored = $this->get(self::KEY_MAX_CONCURRENT_TRANSLATIONS);

        $value = $stored !== null ? (int) $stored : self::DEFAULT_MAX_CONCURRENT_TRANSLATIONS;

        return max(1, min($value, self::MAX_CONCURRENT_TRANSLATIONS_CEILING));
    }

    public function setMaxConcurrentTranslations(int $count): void
    {
        $this->set(self::KEY_MAX_CONCURRENT_TRANSLATIONS, (string) max(1, min($count, self::MAX_CONCURRENT_TRANSLATIONS_CEILING)));
    }

    /**
     * Returns null (rather than 0) for a model with no known rate - the caller needs to tell
     * "genuinely free" apart from "we don't know this model's price", since the latter should
     * show as unknown on the cost dashboard, not silently count as $0.
     */
    public function estimateCost(string $model, int $inputTokens, int $outputTokens): ?float
    {
        $rate = self::MODEL_PRICING_PER_MILLION_TOKENS[$model] ?? null;

        if (! $rate) {
            return null;
        }

        return ($inputTokens / 1_000_000 * $rate['input']) + ($outputTokens / 1_000_000 * $rate['output']);
    }

    /**
     * Validates an API key by hitting the provider's own lightweight "list models" endpoint -
     * real auth check, but free and generates no tokens, unlike a completion call.
     *
     * @return array{ok: bool, message: string}
     */
    public function testConnection(string $provider, string $apiKey): array
    {
        if ($apiKey === '') {
            return ['ok' => false, 'message' => 'No API key to test - type one in first.'];
        }

        return match ($provider) {
            'claude' => $this->testClaude($apiKey),
            'chatgpt' => $this->testChatgpt($apiKey),
            default => ['ok' => false, 'message' => "Unknown provider \"{$provider}\"."],
        };
    }

    private function testClaude(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
            ])->timeout(15)->get('https://api.anthropic.com/v1/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if ($response->successful()) {
            $count = count($response->json('data', []));

            return ['ok' => true, 'message' => "Connected - {$count} model(s) available."];
        }

        return ['ok' => false, 'message' => $this->apiErrorMessage($response)];
    }

    private function testChatgpt(string $apiKey): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$apiKey,
            ])->timeout(15)->get('https://api.openai.com/v1/models');
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Connection error: '.$e->getMessage()];
        }

        if ($response->successful()) {
            $count = count($response->json('data', []));

            return ['ok' => true, 'message' => "Connected - {$count} model(s) available."];
        }

        return ['ok' => false, 'message' => $this->apiErrorMessage($response)];
    }

    private function apiErrorMessage(\Illuminate\Http\Client\Response $response): string
    {
        $detail = $response->json('error.message') ?? $response->body();

        return 'HTTP '.$response->status().': '.mb_strimwidth((string) $detail, 0, 200, '…');
    }

    private static function apiKeyStorageKey(string $provider): string
    {
        return self::KEY_API_KEY_PREFIX.$provider;
    }

    private function get(string $key): ?string
    {
        return Setting::query()->find($key)?->value;
    }

    private function set(string $key, string $value): void
    {
        Setting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
    }
}
