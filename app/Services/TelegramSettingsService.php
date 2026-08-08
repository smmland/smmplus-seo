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
    private const KEY_POST_SLOTS = 'telegram_post_time_slots';
    private const KEY_BLOG_SUMMARY_PROMPT = 'telegram_prompt_blog_summary';
    private const KEY_SERVICE_ANNOUNCEMENT_PROMPT = 'telegram_prompt_service_announcement';
    private const KEY_LAST_WEEKLY_PLAN_RUN_AT = 'telegram_last_weekly_plan_run_at';
    private const KEY_CHANNEL_CAPTURE_ENABLED = 'telegram_channel_capture_enabled';
    private const KEY_LAST_UPDATE_ID = 'telegram_last_update_id';

    private const DEFAULT_ENABLED = false;
    private const DEFAULT_IMAGE_GENERATION_ENABLED = true;
    private const DEFAULT_POSTS_PER_DAY = 1;
    private const DEFAULT_CHANNEL_CAPTURE_ENABLED = false;

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
        - Read the recent past posts below and write something that reads differently from all of
          them - a different opening line, a different angle, different phrasing. Don't reuse the
          same hook, structure, or specific wording twice in a row.

        ## Article title
        {{title}}

        ## Article summary (meta description)
        {{meta_description}}

        ## Article body (HTML, for context - do not just repeat it verbatim)
        {{content}}

        ## URL to link to
        {{url}}

        ## Recent past posts to this channel (avoid repeating their wording/angle)
        {{recent_posts}}
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
        - Read the recent past posts below and vary your phrasing/opening from all of them - don't
          reuse the same sentence structure or wording every time.

        ## Recent past posts to this channel (avoid repeating their wording/angle)
        {{recent_posts}}
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

    /**
     * One time window per post-per-day slot, in order (1st post of the day, 2nd, ...) - see
     * TelegramPostGeneratorService::generateSlotTimes(). A window with start === end is an exact
     * fixed time; start < end picks a random time in that range each day, independently per day.
     * Reconciled against the current postsPerDay count on every read (trimmed if it shrank,
     * padded with a sensible default if it grew) so the two settings can never drift out of sync
     * with each other - there's no separate "how many slots" setting to keep in step by hand.
     *
     * @return list<array{start: string, end: string}>
     */
    public function getPostSlots(): array
    {
        $count = $this->getPostsPerDay();
        $stored = $this->get(self::KEY_POST_SLOTS);
        $slots = $stored ? (json_decode($stored, true) ?: []) : [];

        $slots = array_values(array_slice($slots, 0, $count));

        while (count($slots) < $count) {
            $slots[] = $this->defaultSlot(count($slots), $count);
        }

        return $slots;
    }

    public function setPostSlots(array $slots): void
    {
        $normalized = collect($slots)
            ->map(function ($slot) {
                $start = $slot['start'] ?? '09:00';
                $end = $slot['end'] ?? $start;

                return ['start' => $start, 'end' => $end];
            })
            ->values()
            ->all();

        $this->set(self::KEY_POST_SLOTS, json_encode($normalized));
    }

    // Evenly spread across the day starting at 09:00 (a plausible "channel is active" start
    // rather than midnight), each a fixed point (start === end) until the admin narrows it into
    // a real window - matches nobody's real preference exactly, but gives a sane starting point
    // instead of forcing every slot to be configured before anything can be scheduled at all.
    private function defaultSlot(int $index, int $count): array
    {
        $minutesFromMidnight = (9 * 60 + intdiv(24 * 60, max(1, $count)) * $index) % (24 * 60);
        $time = sprintf('%02d:%02d', intdiv($minutesFromMidnight, 60), $minutesFromMidnight % 60);

        return ['start' => $time, 'end' => $time];
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

    // Independent of isEnabled() (the master posting toggle) - capturing what's already posted
    // to the channel is a different concern from generating/sending new content, so it can be on
    // even while the other is off (e.g. only watching the channel, not posting to it yet).
    public function isChannelCaptureEnabled(): bool
    {
        $stored = $this->get(self::KEY_CHANNEL_CAPTURE_ENABLED);

        return $stored !== null ? (bool) (int) $stored : self::DEFAULT_CHANNEL_CAPTURE_ENABLED;
    }

    public function setChannelCaptureEnabled(bool $enabled): void
    {
        $this->set(self::KEY_CHANNEL_CAPTURE_ENABLED, $enabled ? '1' : '0');
    }

    // Telegram's getUpdates cursor - the highest update_id already processed, so each poll only
    // asks for what's new (offset = this + 1) instead of redelivering the same updates forever.
    // See TelegramCaptureChannelPostsCommand.
    public function getLastUpdateId(): ?int
    {
        $stored = $this->get(self::KEY_LAST_UPDATE_ID);

        return $stored !== null ? (int) $stored : null;
    }

    public function setLastUpdateId(int $updateId): void
    {
        $this->set(self::KEY_LAST_UPDATE_ID, (string) $updateId);
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
