<?php

namespace App\Services;

use App\Models\Language;
use App\Models\TelegramPost;
use App\Models\Url;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Turns the site's own content into draft telegram_posts rows for the admin to review - never
 * sends anything itself (see TelegramSendQueueCommand for that). Two entry points, one per
 * source: topUpBlogPlan() (blog articles, a rolling week ahead) and draftServiceChanges()
 * (service catalog add/update/remove events, drafted immediately with a short review window).
 */
class TelegramPostGeneratorService
{
    private const IMAGE_DIR = 'telegram/images';

    // How many recent posts get fed back into the prompt as "don't repeat this" context - see
    // recentMessagesContext(). Enough to give the AI a real sense of what's already been said
    // without bloating the prompt or the token bill.
    private const RECENT_POSTS_CONTEXT_LIMIT = 8;

    // Where BlogContentExtractionService::assetUrl() points a locally-downloaded image at - see
    // its own $marker note there. Used here to recognize "this src is already a local file" vs
    // "this is still an external URL that needs fetching."
    private const LOCAL_BLOG_ASSET_MARKER = '/blog-content/';

    public function __construct(
        private readonly TelegramSettingsService $settings,
        private readonly TelegramContentAiService $contentAi,
        private readonly TelegramImageAiService $imageAi,
    ) {}

    /**
     * Tops up the rolling blog-summary schedule rather than generating a fixed batch once a
     * week - a missed run (or a manual click) just fills in whatever's short of
     * postsPerDay x BLOG_PLAN_WINDOW_DAYS, so this is safe to call as often as it's scheduled
     * (daily) without doubling up.
     *
     * @return array{created: int, message?: string}
     */
    public function topUpBlogPlan(): array
    {
        if (! Schema::hasTable('telegram_posts')) {
            return ['created' => 0, 'message' => 'Database update needed first - go to General Settings and click "Update database", then try again.'];
        }

        if (! $this->settings->isEnabled()) {
            return ['created' => 0, 'message' => 'Telegram integration is disabled.'];
        }

        $postsPerDay = $this->settings->getPostsPerDay();
        $totalWanted = $postsPerDay * TelegramSettingsService::BLOG_PLAN_WINDOW_DAYS;

        $windowEnd = now()->addDays(TelegramSettingsService::BLOG_PLAN_WINDOW_DAYS);

        $alreadyScheduled = TelegramPost::query()
            ->where('type', TelegramPost::TYPE_BLOG_SUMMARY)
            ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
            ->where('scheduled_at', '<=', $windowEnd)
            ->count();

        $needed = max(0, $totalWanted - $alreadyScheduled);

        if ($needed === 0) {
            return ['created' => 0, 'message' => "The next {$postsPerDay}/day x ".TelegramSettingsService::BLOG_PLAN_WINDOW_DAYS." day window already has {$alreadyScheduled} post(s) scheduled (pending or confirmed) - check the list below. Lower here means nothing to add right now, not that anything failed."];
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';
        $targetLanguage = Language::query()->where('code', $defaultLang)->value('name') ?? $defaultLang;

        // Never posted about, oldest-extracted first, so the very first topics ever extracted
        // get covered before newer ones - and if that pool runs dry, top up with whichever
        // topics were posted about longest ago (or never), so the plan can keep going instead of
        // just stopping once every article's had one post.
        $neverUsedKeys = Url::query()
            ->where('lang', $defaultLang)
            ->whereNotNull('content_extracted_at')
            ->whereNotIn('group_key', TelegramPost::query()->where('type', TelegramPost::TYPE_BLOG_SUMMARY)->pluck('related_key'))
            ->orderBy('content_extracted_at')
            ->limit($needed)
            ->get();

        $candidates = $neverUsedKeys;

        if ($candidates->count() < $needed) {
            $moreNeeded = $needed - $candidates->count();
            $excludeKeys = $candidates->pluck('group_key');

            $fallback = Url::query()
                ->where('lang', $defaultLang)
                ->whereNotNull('content_extracted_at')
                ->whereNotIn('group_key', $excludeKeys)
                ->get()
                ->sortBy(fn (Url $url) => TelegramPost::query()
                    ->where('type', TelegramPost::TYPE_BLOG_SUMMARY)
                    ->where('related_key', $url->group_key)
                    ->max('created_at') ?? '1970-01-01')
                ->take($moreNeeded);

            $candidates = $candidates->concat($fallback);
        }

        if ($candidates->isEmpty()) {
            return ['created' => 0, 'message' => 'No blog content available yet - extract some articles on the Blog Translation page first.'];
        }

        $recentPosts = $this->recentMessagesContext(TelegramPost::TYPE_BLOG_SUMMARY);

        $prepared = $candidates->mapWithKeys(function (Url $url) use ($targetLanguage, $recentPosts) {
            $contentText = $this->readContentText($url->content_extraction_path);

            return [$url->id => [
                'prompt' => $this->contentAi->buildBlogSummaryPrompt([
                    'title' => $url->article_title,
                    'meta_description' => $url->meta_description,
                    'content' => Str::limit($contentText, 4000),
                    'url' => $url->source_url,
                    'recent_posts' => $recentPosts,
                ], $targetLanguage),
            ]];
        });

        $results = $this->contentAi->generateMany($prepared);

        $interval = intdiv(24 * 60, max(1, $postsPerDay));
        $latestScheduled = TelegramPost::query()
            ->where('type', TelegramPost::TYPE_BLOG_SUMMARY)
            ->whereIn('status', TelegramPost::SENDABLE_STATUSES)
            ->max('scheduled_at');
        $nextSlot = ($latestScheduled ? Carbon::parse($latestScheduled) : now())->addMinutes($interval);

        $created = 0;
        $firstFailureMessage = null;

        foreach ($candidates as $url) {
            $result = $results[$url->id] ?? null;

            if (! $result || ! $result['ok']) {
                // Every candidate reuses the same AI provider/key, so one failure (bad/missing
                // API key, provider outage, ...) almost always means they all failed the same
                // way - keeping just the first is enough to point at the real cause without a
                // wall of duplicate errors.
                $firstFailureMessage ??= $result['message'] ?? 'The AI request failed with no error message.';

                continue;
            }

            [$imagePath, $imageSource, $imageCost] = $this->resolveImage(
                $this->extractFirstImageSrc($url->content_extraction_path),
                fn () => 'A clean, modern social media banner illustrating the blog article titled "'.$url->article_title.'". No text or letters in the image. Flat, minimal, professional style.',
            );

            TelegramPost::create([
                'type' => TelegramPost::TYPE_BLOG_SUMMARY,
                'lang' => $defaultLang,
                'related_key' => $url->group_key,
                'title' => $url->article_title ?? $url->group_key,
                'message_text' => $result['message'],
                'image_path' => $imagePath,
                'image_source' => $imageSource,
                'scheduled_at' => $nextSlot,
                'status' => TelegramPost::STATUS_PENDING,
                'ai_provider' => $result['provider'] ?? null,
                'ai_model' => $result['model'] ?? null,
                'input_tokens' => $result['input_tokens'] ?? null,
                'output_tokens' => $result['output_tokens'] ?? null,
                'estimated_cost_usd' => $result['estimated_cost_usd'] ?? null,
                'image_cost_usd' => $imageCost,
            ]);

            $created++;
            $nextSlot = $nextSlot->copy()->addMinutes($interval);
        }

        $this->settings->recordWeeklyPlanRun();

        if ($created === 0 && $firstFailureMessage !== null) {
            return ['created' => 0, 'message' => "Found {$candidates->count()} article(s) to write about, but AI generation failed for all of them: {$firstFailureMessage}"];
        }

        return ['created' => $created];
    }

    /**
     * Drafts one post per service catalog change, called right after
     * ServiceCatalogService::syncDefaultCatalog() by RefreshServiceCatalogCommand - each list
     * item is ['service_key' => ..., 'title' => ..., 'category_title' => ...]. Scheduled a short
     * SERVICE_CHANGE_REVIEW_MINUTES out rather than the blog plan's week-ahead window, so the
     * channel stays current without needing daily admin attention - reject is still the only way
     * to stop one from sending.
     *
     * @param  list<array{service_key: string, title: ?string, category_title: ?string}>  $added
     * @param  list<array{service_key: string, title: ?string, category_title: ?string}>  $changed
     * @param  list<array{service_key: string, title: ?string, category_title: ?string}>  $removed
     * @return array{created: int}
     */
    public function draftServiceChanges(array $added, array $changed, array $removed): array
    {
        if (! Schema::hasTable('telegram_posts') || ! $this->settings->isEnabled()) {
            return ['created' => 0];
        }

        $events = [
            ...array_map(fn ($s) => ['type' => TelegramPost::TYPE_SERVICE_ADDED, 'changeType' => 'added', 'service' => $s], $added),
            ...array_map(fn ($s) => ['type' => TelegramPost::TYPE_SERVICE_UPDATED, 'changeType' => 'updated', 'service' => $s], $changed),
            ...array_map(fn ($s) => ['type' => TelegramPost::TYPE_SERVICE_REMOVED, 'changeType' => 'removed', 'service' => $s], $removed),
        ];

        if (empty($events)) {
            return ['created' => 0];
        }

        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';
        $targetLanguage = Language::query()->where('code', $defaultLang)->value('name') ?? $defaultLang;
        $recentPosts = $this->recentMessagesContext(...TelegramPost::SERVICE_TYPES);

        $prepared = collect($events)->mapWithKeys(fn ($event, $i) => [$i => [
            'prompt' => $this->contentAi->buildServiceAnnouncementPrompt([
                'change_type' => $event['changeType'],
                'service_title' => $event['service']['title'],
                'category_title' => $event['service']['category_title'],
                'recent_posts' => $recentPosts,
            ], $targetLanguage),
        ]]);

        $results = $this->contentAi->generateMany($prepared);

        $created = 0;

        foreach ($events as $i => $event) {
            $result = $results[$i] ?? null;

            if (! $result || ! $result['ok']) {
                continue;
            }

            $service = $event['service'];
            $label = match ($event['type']) {
                TelegramPost::TYPE_SERVICE_ADDED => 'New service: ',
                TelegramPost::TYPE_SERVICE_UPDATED => 'Updated: ',
                default => 'Removed: ',
            };

            [$imagePath, $imageSource, $imageCost] = $this->resolveImage(
                null,
                fn () => 'A clean, modern social media banner for an SMM panel service called "'.($service['title'] ?? $service['service_key']).'" in the "'.($service['category_title'] ?? 'general').'" category. No text or letters in the image. Flat, minimal, professional style.',
            );

            TelegramPost::create([
                'type' => $event['type'],
                'lang' => $defaultLang,
                'related_key' => $service['service_key'],
                'title' => $label.($service['title'] ?? $service['service_key']),
                'message_text' => $result['message'],
                'image_path' => $imagePath,
                'image_source' => $imageSource,
                'scheduled_at' => now()->addMinutes(TelegramSettingsService::SERVICE_CHANGE_REVIEW_MINUTES),
                'status' => TelegramPost::STATUS_PENDING,
                'ai_provider' => $result['provider'] ?? null,
                'ai_model' => $result['model'] ?? null,
                'input_tokens' => $result['input_tokens'] ?? null,
                'output_tokens' => $result['output_tokens'] ?? null,
                'estimated_cost_usd' => $result['estimated_cost_usd'] ?? null,
                'image_cost_usd' => $imageCost,
            ]);

            $created++;
        }

        return ['created' => $created];
    }

    /**
     * Formats the most recent posts of the given type(s) as "avoid repeating this" context fed
     * into the {{recent_posts}} placeholder (TelegramContentAiService) - this is what fulfils
     * "check what's already been said so nothing repetitive or too similar goes out again".
     * Excludes rejected posts (explicitly discarded, not representative of the channel's actual
     * voice) but includes everything else - even a still-pending draft is content the AI
     * shouldn't echo. telegram_posts itself is the permanent history (rows are never pruned,
     * only ever deleted one at a time by an admin from the Queue page), so this always has the
     * full record to draw from.
     */
    private function recentMessagesContext(string ...$types): string
    {
        $posts = TelegramPost::query()
            ->whereIn('type', $types)
            ->where('status', '!=', TelegramPost::STATUS_REJECTED)
            ->orderByDesc('created_at')
            ->limit(self::RECENT_POSTS_CONTEXT_LIMIT)
            ->pluck('message_text');

        if ($posts->isEmpty()) {
            return '(none yet)';
        }

        return $posts->map(fn ($text, $i) => ($i + 1).'. '.Str::limit(trim((string) $text), 200))->implode("\n");
    }

    private function readContentText(?string $contentPath): string
    {
        if (! $contentPath || ! Storage::disk('public')->exists($contentPath)) {
            return '';
        }

        $html = Storage::disk('public')->get($contentPath);

        return trim(preg_replace('/\s+/', ' ', strip_tags((string) $html)));
    }

    private function extractFirstImageSrc(?string $contentPath): ?string
    {
        if (! $contentPath || ! Storage::disk('public')->exists($contentPath)) {
            return null;
        }

        $html = Storage::disk('public')->get($contentPath);

        if (! $html) {
            return null;
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $imgs = $doc->getElementsByTagName('img');

        if ($imgs->length === 0) {
            return null;
        }

        $src = $imgs->item(0)->getAttribute('src');

        return $src !== '' ? $src : null;
    }

    /**
     * Resolves an article's image src (data URI, already-local asset, or still-external URL) to
     * a path on the 'public' disk, downloading/decoding it once if needed. Falls back to
     * generating one with AI (only if that's enabled in settings) when there's no usable src at
     * all, so a post is never sent with no image just because the source article had none.
     *
     * @return array{0: ?string, 1: string, 2: ?float} [diskPath, imageSource, imageCostUsd]
     */
    private function resolveImage(?string $src, \Closure $aiPromptIfMissing): array
    {
        if ($src !== null) {
            if (str_starts_with($src, 'data:image/')) {
                if (preg_match('/^data:image\/([a-zA-Z0-9.+-]+);base64,(.+)$/', $src, $m)) {
                    $bytes = base64_decode($m[2], true);

                    if ($bytes !== false) {
                        $ext = strtolower(explode('+', $m[1])[0]);
                        $path = self::IMAGE_DIR.'/'.Str::uuid()->toString().'.'.$ext;
                        Storage::disk('public')->put($path, $bytes);

                        return [$path, TelegramPost::IMAGE_ARTICLE, null];
                    }
                }
            } elseif (str_contains($src, self::LOCAL_BLOG_ASSET_MARKER)) {
                $diskPath = substr($src, strpos($src, self::LOCAL_BLOG_ASSET_MARKER) + strlen(self::LOCAL_BLOG_ASSET_MARKER));

                if (Storage::disk('public')->exists($diskPath)) {
                    return [$diskPath, TelegramPost::IMAGE_ARTICLE, null];
                }
            } else {
                try {
                    $response = Http::timeout(15)->get($src);
                } catch (\Throwable) {
                    $response = null;
                }

                if ($response && $response->successful()) {
                    $ext = pathinfo(parse_url($src, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION) ?: 'jpg';
                    $path = self::IMAGE_DIR.'/'.Str::uuid()->toString().'.'.$ext;
                    Storage::disk('public')->put($path, $response->body());

                    return [$path, TelegramPost::IMAGE_ARTICLE, null];
                }
            }
        }

        if (! $this->settings->isImageGenerationEnabled()) {
            return [null, TelegramPost::IMAGE_NONE, null];
        }

        $generated = $this->imageAi->generate($aiPromptIfMissing());

        return $generated['ok']
            ? [$generated['path'], TelegramPost::IMAGE_AI_GENERATED, $generated['cost_usd'] ?? null]
            : [null, TelegramPost::IMAGE_NONE, null];
    }
}
