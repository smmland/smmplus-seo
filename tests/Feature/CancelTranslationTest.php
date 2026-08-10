<?php

namespace Tests\Feature;

use App\Filament\Pages\BlogTranslationQueue;
use App\Filament\Pages\CategoryTranslationQueue;
use App\Filament\Pages\ServiceTranslationQueue;
use App\Models\BlogTranslationJob;
use App\Models\CategoryTranslationJob;
use App\Models\ServiceTranslationJob;
use App\Models\User;
use App\Support\PanelSection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CancelTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsTranslationEditor(): User
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'granted_sections' => [PanelSection::key(PanelSection::TRANSLATION, PanelSection::TIER_EDIT)],
        ]);
        $this->actingAs($user);

        return $user;
    }

    private function actingAsTranslationViewerOnly(): User
    {
        $user = User::factory()->create([
            'is_super_admin' => false,
            'granted_sections' => [PanelSection::key(PanelSection::TRANSLATION, PanelSection::TIER_VIEW)],
        ]);
        $this->actingAs($user);

        return $user;
    }

    // --- Blog ---

    public function test_blog_cancel_marks_a_running_job_cancelled(): void
    {
        $this->actingAsTranslationEditor();
        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::RUNNING]);

        Livewire::test(BlogTranslationQueue::class)
            ->call('cancelTranslation', 'g1', 'fr')
            ->assertNotified('Translation cancelled');

        $this->assertSame(BlogTranslationJob::CANCELLED, $job->fresh()->status);
    }

    public function test_blog_cancel_leaves_a_done_job_untouched(): void
    {
        $this->actingAsTranslationEditor();
        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::DONE]);

        Livewire::test(BlogTranslationQueue::class)
            ->call('cancelTranslation', 'g1', 'fr')
            ->assertNotified('Nothing to cancel');

        $this->assertSame(BlogTranslationJob::DONE, $job->fresh()->status);
    }

    public function test_blog_cancel_requires_edit_access(): void
    {
        $this->actingAsTranslationViewerOnly();
        $job = BlogTranslationJob::create(['group_key' => 'g1', 'target_lang' => 'fr', 'status' => BlogTranslationJob::RUNNING]);

        Livewire::test(BlogTranslationQueue::class)->call('cancelTranslation', 'g1', 'fr');

        $this->assertSame(BlogTranslationJob::RUNNING, $job->fresh()->status);
    }

    // --- Service (description + title are independent) ---

    public function test_service_cancel_description_does_not_affect_title_job(): void
    {
        $this->actingAsTranslationEditor();
        $desc = ServiceTranslationJob::create([
            'service_key' => 's1', 'target_lang' => 'fr', 'field' => ServiceTranslationJob::FIELD_DESCRIPTION,
            'status' => ServiceTranslationJob::RUNNING,
        ]);
        $title = ServiceTranslationJob::create([
            'service_key' => 's1', 'target_lang' => 'fr', 'field' => ServiceTranslationJob::FIELD_TITLE,
            'status' => ServiceTranslationJob::RUNNING,
        ]);

        Livewire::test(ServiceTranslationQueue::class)->call('cancelTranslation', 's1', 'fr');

        $this->assertSame(ServiceTranslationJob::CANCELLED, $desc->fresh()->status);
        $this->assertSame(ServiceTranslationJob::RUNNING, $title->fresh()->status);
    }

    public function test_service_cancel_title_does_not_affect_description_job(): void
    {
        $this->actingAsTranslationEditor();
        $desc = ServiceTranslationJob::create([
            'service_key' => 's1', 'target_lang' => 'fr', 'field' => ServiceTranslationJob::FIELD_DESCRIPTION,
            'status' => ServiceTranslationJob::QUEUED,
        ]);
        $title = ServiceTranslationJob::create([
            'service_key' => 's1', 'target_lang' => 'fr', 'field' => ServiceTranslationJob::FIELD_TITLE,
            'status' => ServiceTranslationJob::QUEUED,
        ]);

        Livewire::test(ServiceTranslationQueue::class)->call('cancelTitleTranslation', 's1', 'fr');

        $this->assertSame(ServiceTranslationJob::QUEUED, $desc->fresh()->status);
        $this->assertSame(ServiceTranslationJob::CANCELLED, $title->fresh()->status);
    }

    // --- Category ---

    public function test_category_cancel_marks_a_queued_job_cancelled(): void
    {
        $this->actingAsTranslationEditor();
        $job = CategoryTranslationJob::create(['category_id' => 'c1', 'target_lang' => 'fr', 'status' => CategoryTranslationJob::QUEUED]);

        Livewire::test(CategoryTranslationQueue::class)
            ->call('cancelTranslation', 'c1', 'fr')
            ->assertNotified('Translation cancelled');

        $this->assertSame(CategoryTranslationJob::CANCELLED, $job->fresh()->status);
    }
}
