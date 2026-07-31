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

    private const DEFAULT_PROVIDER = 'claude';

    // Editable on the settings page rather than hardcoded permanently - API providers retire
    // model ids over time, so these are just sane starting points, not guaranteed forever.
    private const DEFAULT_MODELS = [
        'claude' => 'claude-sonnet-4-5-20250929',
        'chatgpt' => 'gpt-4o',
    ];

    // Verbatim from smmland/smmplus-extensions (smmplus-tools/popup.js, buildPrompt()) per
    // explicit request to use it as the starting point - only the dynamic JS template values
    // (folder, data.html, ...) were swapped for this system's {{token}} placeholders. Everything
    // else (the git/repo workflow, the RTL rules, the meta_seo.txt format) is untouched, even
    // where it doesn't yet match how this panel will actually consume the AI's reply - expected
    // to be edited once the real translate call is built.
    private const DEFAULT_BLOG_TRANSLATION_PROMPT = <<<'PROMPT'
        I need you to create a new blog translation folder for my website.

        ## Project context
        - Repo: smmland/smmplus-website
        - Branch: claude/add-blog-folder-structure-baXrG
        - All blog files live under: blogs/<folder-name>/
        - Files are pure HTML fragments (NO <html>, <head>, <body>, <style> wrappers)
        - All styling via Tailwind CSS utility classes — zero inline style="" attributes
        - Content is injected via {{ post['content']|raw }} in a Twig CMS

        ## Task
        Create the folder: blogs/{{slug}}/

        ### Step 1 — Translations
        Translate en.html into these 17 languages and save each as a separate file:
        ru, tr, bp, ko, ar, es, th, vi, fr, zh, de, id, it, ja, pl, uk, fa

        ### Step 2 — RTL fix (ar.html and fa.html only)
        For Arabic and Persian files apply these rules:
        - Add dir="rtl" to block-level elements only: p, h1, h2, h3, h4, h5, h6, li, ul, ol, td, th
        - Do NOT add dir="rtl" to inline elements: span, a
        - Replace pl-12 with pr-12 on all <ul> elements

        ### Step 3 — meta_seo.txt
        Create blogs/{{slug}}/meta_seo.txt using the SEO data I provide.
        File format for each language:
        [lang_code]
        Page title:
        <title>

        Meta-keywords:
        <keywords>

        Meta-description:
        <description>

        ---

        Supported lang codes: en, ar, bp, de, es, fa, fr, id, it, ja, ko, pl, ru, th, tr, uk, vi, zh

        ### Step 4 — Commit & push
        Commit all files with a clear message and push to the branch above.

        ---

        ## Input — English HTML content:
        {{content}}

        ## Input — SEO metadata (English only, translate the rest):
        Page title: {{meta_title}}
        Meta-keywords: {{meta_keywords}}
        Meta-description: {{meta_description}}
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
