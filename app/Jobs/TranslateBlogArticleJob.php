<?php

namespace App\Jobs;

use App\Models\BlogTranslationJob;
use App\Models\Url;
use App\Services\BlogAiTranslationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class TranslateBlogArticleJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    // Runs off the scheduled queue worker (routes/console.php), not inside a web request, so
    // there's no shared-host PHP execution-time or reverse-proxy ceiling to work around here -
    // a single AI call for a long article can legitimately take several minutes, and this just
    // needs to outlast that. The queue worker itself must be started with a matching
    // --timeout, since that flag (not this property) is what actually enforces a ceiling.
    public int $timeout = 900;

    public function __construct(
        public readonly int $sourceUrlId,
        public readonly string $targetLangCode,
        public readonly string $groupKey,
    ) {}

    public function handle(BlogAiTranslationService $translator): void
    {
        $tracker = BlogTranslationJob::query()
            ->where('group_key', $this->groupKey)
            ->where('target_lang', $this->targetLangCode)
            ->first();

        $tracker?->update(['status' => BlogTranslationJob::RUNNING]);

        $sourceRow = Url::find($this->sourceUrlId);

        if (! $sourceRow) {
            $tracker?->update(['status' => BlogTranslationJob::FAILED, 'message' => 'The source article no longer exists.']);

            return;
        }

        $result = $translator->translate($sourceRow, $this->targetLangCode);

        $tracker?->update([
            'status' => $result['ok'] ? BlogTranslationJob::DONE : BlogTranslationJob::FAILED,
            'message' => $result['message'],
        ]);
    }

    // The queue worker's own --timeout kills the process outright rather than throwing inside
    // handle(), so this is the only reliable place to record "it never finished" for a run that
    // overran - without it, a killed job would leave the tracker stuck on "running" forever.
    public function failed(?Throwable $exception): void
    {
        BlogTranslationJob::query()
            ->where('group_key', $this->groupKey)
            ->where('target_lang', $this->targetLangCode)
            ->update([
                'status' => BlogTranslationJob::FAILED,
                'message' => $exception?->getMessage() ?? 'The translation job failed or timed out.',
            ]);
    }
}
