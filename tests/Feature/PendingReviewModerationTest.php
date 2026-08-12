<?php

namespace Tests\Feature;

use App\Filament\Resources\PendingReviewResource;
use App\Filament\Resources\PendingReviewResource\Pages\ListPendingReviews;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class PendingReviewModerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Review::query()->delete();
    }

    private function actingAsSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($user);

        return $user;
    }

    private function pendingReview(array $overrides = []): Review
    {
        return Review::create(array_merge([
            'author_name' => 'Pending Reviewer',
            'rating' => 5,
            'body' => 'Awaiting moderation.',
            'is_approved' => false,
            'status' => Review::STATUS_PENDING,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_only_pending_reviews_are_listed(): void
    {
        $this->actingAsSuperAdmin();
        $pending = $this->pendingReview(['author_name' => 'Waiting']);
        Review::create(['author_name' => 'Already Approved', 'rating' => 5, 'body' => 'x', 'is_approved' => true, 'status' => Review::STATUS_APPROVED, 'sort_order' => 0]);
        Review::create(['author_name' => 'Already Rejected', 'rating' => 5, 'body' => 'x', 'is_approved' => false, 'status' => Review::STATUS_REJECTED, 'sort_order' => 0]);

        Livewire::test(ListPendingReviews::class)
            ->assertCanSeeTableRecords([$pending])
            ->assertCountTableRecords(1);
    }

    public function test_newest_submission_is_listed_first(): void
    {
        $this->actingAsSuperAdmin();
        $older = $this->pendingReview(['author_name' => 'Older']);
        $older->forceFill(['created_at' => now()->subHour()])->save();
        $newer = $this->pendingReview(['author_name' => 'Newer']);

        Livewire::test(ListPendingReviews::class)
            ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_approving_sets_is_approved_and_status_and_removes_it_from_the_queue(): void
    {
        $this->actingAsSuperAdmin();
        $review = $this->pendingReview();

        Livewire::test(ListPendingReviews::class)
            ->callTableAction('approve', $review)
            ->assertCanNotSeeTableRecords([$review]);

        $review->refresh();
        $this->assertTrue($review->is_approved);
        $this->assertSame(Review::STATUS_APPROVED, $review->status);
    }

    public function test_rejecting_sets_status_rejected_keeps_is_approved_false_and_removes_it_from_the_queue(): void
    {
        $this->actingAsSuperAdmin();
        $review = $this->pendingReview();

        Livewire::test(ListPendingReviews::class)
            ->callTableAction('reject', $review)
            ->assertCanNotSeeTableRecords([$review]);

        $review->refresh();
        $this->assertFalse($review->is_approved);
        $this->assertSame(Review::STATUS_REJECTED, $review->status);
    }

    public function test_an_approved_review_is_immediately_eligible_for_the_public_api(): void
    {
        $this->actingAsSuperAdmin();
        $review = $this->pendingReview(['lang' => 'en']);

        Livewire::test(ListPendingReviews::class)->callTableAction('approve', $review);

        $response = $this->getJson('/api/reviews?lang=en');
        $names = collect($response->json('reviews'))->pluck('author_name');
        $this->assertTrue($names->contains('Pending Reviewer'));
    }

    public function test_a_rejected_review_never_appears_again_after_a_later_sync(): void
    {
        $this->actingAsSuperAdmin();
        $review = $this->pendingReview();

        Livewire::test(ListPendingReviews::class)->callTableAction('reject', $review);

        // Nothing should re-queue a rejected review for moderation on its own.
        Livewire::test(ListPendingReviews::class)->assertCountTableRecords(0);
    }

    public function test_navigation_badge_reflects_the_pending_count(): void
    {
        $this->actingAsSuperAdmin();
        $this->pendingReview(['author_name' => 'One']);
        $this->pendingReview(['author_name' => 'Two']);

        $this->assertSame('2', PendingReviewResource::getNavigationBadge());
    }

    public function test_navigation_badge_is_null_when_nothing_is_pending(): void
    {
        $this->actingAsSuperAdmin();

        $this->assertNull(PendingReviewResource::getNavigationBadge());
    }

    public function test_approve_action_is_hidden_without_edit_access(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'granted_sections' => ['reviews_view']]);
        $this->actingAs($user);
        $review = $this->pendingReview();

        Livewire::test(ListPendingReviews::class)
            ->assertTableActionHidden('approve', $review)
            ->assertTableActionHidden('reject', $review);
    }

    public function test_view_only_access_can_still_see_the_queue(): void
    {
        $user = User::factory()->create(['is_super_admin' => false, 'granted_sections' => ['reviews_view']]);
        $this->actingAs($user);
        $review = $this->pendingReview();

        Livewire::test(ListPendingReviews::class)
            ->assertCanSeeTableRecords([$review]);
    }
}
