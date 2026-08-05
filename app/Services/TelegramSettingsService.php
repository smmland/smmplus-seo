<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;

class TelegramSettingsService
{
    private const KEY_ENABLED = 'telegram_enabled';
    private const KEY_BOT_TOKEN = 'telegram_bot_token';
    private const KEY_CHANNEL_ID = 'telegram_channel_id';
    private const KEY_IMAGE_GENERATION_ENABLED = 'telegram_image_generation_enabled';
    private const KEY_POSTS_PER_DAY = 'telegram_posts_per_day';
    private const KEY_BLOG_SUMMARY_PROMPT = 'telegram_prompt_blog_summary';
    private const KEY_SERVICE_ANNOUNCEMENT_PROMPT = 'telegram_prompt_service_announcement';
    private const KEY_LAST_WEEKLY_PLAN_RUN_AT = 'telegram_last_weekly_plan_run_at';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_IMAGE_GENERATION_ENABLED = true;
    private const DEFAULT_POSTS_PER_DAY = 1;

    // How many days ahead topUpBlogPlan() keeps scheduled - see TelegramPostGeneratorService.
    public const BLOG_PLAN_WINDOW_DAYS = 7;

    // How long a service-change announcement waits before send-queue actually sends it, giving
    // the admin a window to reject/edit it first - see TelegramPostGeneratorService::draftServiceChanges().
    public const SERVICE_CHANGE_REVIEW_MINUTES = 30;

    private const DEFAULT_BLOG_SUMMARY_PROMPT = <<<'PROMPT'
        Write a short, engaging Telegram channel post (in {{target_language}}) promoting the blog
        article below. This is going out to a real audience on a public channel, not a summary for
        internal use - write it like a native {{target_language}}-speaking social media copywriter
        would, not a literal translation or a dry summary.

        Rules:
        - Plain text only - Telegram captions don't render HTML/Markdown reliably here, so no tags,
          no asterisks for bold, no links formatted as [text](url).
        - 2 to 5 short sentences. Make someone want to click through and read the full article.
        - End with the article's URL on its own line.
        - No hashtags unless they'd read naturally to a native speaker, don't force them.

        ## Article title
        {{title}}

        ## Article summary (meta description)
        {{meta_description}}

        ## Article body (HTML, for context - do not just repeat it verbatim)
        {{content}}

        ## URL to link to
        {{url}}
        PROMPT;

    private const DEFAULT_SERVICE_ANNOUNCEMENT_PROMPT = <<<'PROMPT'
        Write a short Telegram channel announcement (in {{target_language}}) about the service
        catalog change below. This is a real post going out to a public channel - write it like a
        native {{target_language}}-speaking social media copywriter would, upbeat but not spammy.

        Change type: {{change_type}}
        Service name: {{service_title}}
        Category: {{category_title}}

        Rules:
        - Plain text only - no HTML, no Markdown formatting.
        - 1 to 3 short sentences.
        - If the change type is "removed", don't apologize excessively - a brief, matter-of-fact
          notice is enough.
        PROMPT;

    public function isEnabled(): bool
    {
        return (bool) (int) ($this->get(self::KEY_ENABLED) ?? (int) self::DEFAULT_ENABLED);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->set(self::KEY_ENABLED, $enabled ? '1' : '0');
    }

    public function hasBotToken(): bool
    {
        return $this->get(self::KEY_BOT_TOKEN) !== null;
    }

    public function getBotToken(): ?string
    {
        $encrypted = $this->get(self::KEY_BOT_TOKEN);

        if ($encrypted === null) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (\Throwable) {
            return null;
        }
    }

    // Same "blank means keep the existing secret" rule as AiSettingsService::setApiKey() - the
    // form field is never pre-filled with the real token.
    public function setBotToken(?string $token): void
    {
        if ($token === null || $token === '') {
            return;
        }

        $this->set(self::KEY_BOT_TOKEN, Crypt::encryptString($token));
    }

    public function clearBotToken(): void
    {
        Setting::query()->where('key', self::KEY_BOT_TOKEN)->delete();
    }

    public function getChannelId(): ?string
    {
        return $this->get(self::KEY_CHANNEL_ID);
    }

    public function setChannelId(?string $channelId): void
    {
        $this->set(self::KEY_CHANNEL_ID, trim((string) $channelId));
    }

    public function isImageGenerationEnabled(): bool
    {
        $stored = $this->get(self::KEY_IMAGE_GENERATION_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_IMAGE_GENERATION_ENABLED;
    }

    public function setImageGenerationEnabled(bool $enabled): void
    {
        $this->set(self::KEY_IMAGE_GENERATION_ENABLED, $enabled ? '1' : '0');
    }

    public function getPostsPerDay(): int
    {
        $stored = $this->get(self::KEY_POSTS_PER_DAY);

        return max(1, $stored !== null ? (int) $stored : self::DEFAULT_POSTS_PER_DAY);
    }

    public function setPostsPerDay(int $count): void
    {
        $this->set(self::KEY_POSTS_PER_DAY, (string) max(1, $count));
    }

    public function getBlogSummaryPrompt(): string
    {
        return $this->get(self::KEY_BLOG_SUMMARY_PROMPT) ?? self::DEFAULT_BLOG_SUMMARY_PROMPT;
    }

    public function setBlogSummaryPrompt(string $prompt): void
    {
        $this->set(self::KEY_BLOG_SUMMARY_PROMPT, $prompt);
    }

    public function defaultBlogSummaryPrompt(): string
    {
        return self::DEFAULT_BLOG_SUMMARY_PROMPT;
    }

    public function getServiceAnnouncementPrompt(): string
    {
        return $this->get(self::KEY_SERVICE_ANNOUNCEMENT_PROMPT) ?? self::DEFAULT_SERVICE_ANNOUNCEMENT_PROMPT;
    }

    public function setServiceAnnouncementPrompt(string $prompt): void
    {
        $this->set(self::KEY_SERVICE_ANNOUNCEMENT_PROMPT, $prompt);
    }

    public function defaultServiceAnnouncementPrompt(): string
    {
        return self::DEFAULT_SERVICE_ANNOUNCEMENT_PROMPT;
    }

    public function getLastWeeklyPlanRunAt(): ?\Illuminate\Support\Carbon
    {
        $stored = $this->get(self::KEY_LAST_WEEKLY_PLAN_RUN_AT);

        return $stored !== null ? \Illuminate\Support\Carbon::parse($stored) : null;
    }

    public function recordWeeklyPlanRun(): void
    {
        $this->set(self::KEY_LAST_WEEKLY_PLAN_RUN_AT, now()->toIso8601String());
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
