<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Review::status is a tri-state (pending/approved/rejected) that is_approved alone can't express -
// is_approved stays the public API's simple on/off switch, kept in sync automatically by
// Review::booted() so every pre-existing call site that only ever touched is_approved (the
// ReviewResource "Approved" toggle/bulk actions, the factory, older tests) still behaves exactly
// as before without needing to know status exists.
class ReviewStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private function makeReview(array $overrides = []): Review
    {
        return Review::create(array_merge([
            'author_name' => 'Test User',
            'rating' => 5,
            'body' => 'Great service.',
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_creating_an_approved_review_without_status_infers_status_approved(): void
    {
        $review = $this->makeReview(['is_approved' => true]);

        $this->assertSame(Review::STATUS_APPROVED, $review->status);
    }

    public function test_creating_an_unapproved_review_without_status_infers_status_pending(): void
    {
        $review = $this->makeReview(['is_approved' => false]);

        $this->assertSame(Review::STATUS_PENDING, $review->status);
    }

    public function test_an_explicit_status_is_not_overridden_by_is_approved(): void
    {
        $review = $this->makeReview(['is_approved' => false, 'status' => Review::STATUS_REJECTED]);

        $this->assertSame(Review::STATUS_REJECTED, $review->status);
    }

    public function test_updating_is_approved_alone_resyncs_status(): void
    {
        $review = $this->makeReview(['is_approved' => true]);
        $this->assertSame(Review::STATUS_APPROVED, $review->status);

        $review->update(['is_approved' => false]);

        $this->assertSame(Review::STATUS_PENDING, $review->fresh()->status);
    }

    public function test_updating_status_alone_does_not_touch_is_approved(): void
    {
        $review = $this->makeReview(['is_approved' => false, 'status' => Review::STATUS_PENDING]);

        $review->update(['status' => Review::STATUS_REJECTED]);

        $this->assertFalse($review->fresh()->is_approved);
        $this->assertSame(Review::STATUS_REJECTED, $review->fresh()->status);
    }

    public function test_approving_via_is_approved_and_status_together_lands_on_the_explicit_status(): void
    {
        $review = $this->makeReview(['is_approved' => false, 'status' => Review::STATUS_PENDING]);

        $review->update(['is_approved' => true, 'status' => Review::STATUS_APPROVED]);

        $this->assertTrue($review->fresh()->is_approved);
        $this->assertSame(Review::STATUS_APPROVED, $review->fresh()->status);
    }
}
