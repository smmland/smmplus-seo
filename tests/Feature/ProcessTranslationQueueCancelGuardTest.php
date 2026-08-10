<?php

namespace Tests\Feature;

use App\Models\BlogTranslationJob;
use App\Services\BlogAiTranslationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProcessTranslationQueueCancelGuardTest extends TestCase
{
    use RefreshDatabase;

    // Reproduces the exact race the conditional completion-update in
    // ProcessBlogTranslationQueueCommand guards against: an admin clicks Cancel (a separate
    // request) while the AI call for that same job is still in flight. The translator mock's
    // side effect stands in for that separate request landing mid-command.
    public function test_a_job_cancelled_while_its_translation_call_is_in_flight_stays_cancelled(): void
    {
        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::QUEUED]);

        $translator = $this->mock(BlogAiTranslationService::class);
        $translator->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturnUsing(function (Collection $jobs) use ($job) {
                // Simulate the admin's Cancel click landing between "marked RUNNING" and "the AI
                // response comes back" - exactly the window this guard exists for.
                $job->update(['status' => BlogTranslationJob::CANCELLED]);

                return [$job->id => ['ok' => true, 'message' => 'Translated.']];
            });

        $this->artisan('translation:process-queue')->assertSuccessful();

        $this->assertSame(BlogTranslationJob::CANCELLED, $job->fresh()->status);
    }

    public function test_a_normal_job_still_completes_when_nothing_cancels_it(): void
    {
        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::QUEUED]);

        $translator = $this->mock(BlogAiTranslationService::class);
        $translator->shouldReceive('translateManyConcurrently')
            ->once()
            ->andReturn([$job->id => ['ok' => true, 'message' => 'Translated.']]);

        $this->artisan('translation:process-queue')->assertSuccessful();

        $this->assertSame(BlogTranslationJob::DONE, $job->fresh()->status);
    }
}
