<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Review;
use App\Services\ReviewsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// schema.org's aggregateRating (what actually makes Google show stars in search results) needs
// the real total across every review, not a sample - GET /api/reviews/summary and the "aggregate"
// key on GET /api/reviews both report the true average/count across ALL approved reviews for a
// language, independent of the rotating subset a review carousel happens to display.
class ReviewAggregateApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Review::query()->delete();
    }

    private function makeReview(array $overrides = []): Review
    {
        return Review::create(array_merge([
            'author_name' => 'Test User',
            'rating' => 5,
            'body' => 'Great service.',
            'lang' => 'en',
            'is_approved' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_summary_reports_the_true_average_and_count_across_every_approved_review(): void
    {
        // 25 approved reviews (more than any display limit) with a known average.
        foreach ([5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 5, 4, 4, 4, 4, 4] as $rating) {
            $this->makeReview(['rating' => $rating]);
        }

        $response = $this->getJson('/api/reviews/summary?lang=en');

        $response->assertOk();
        $this->assertSame(25, $response->json('review_count'));
        $this->assertSame(4.8, $response->json('rating_value')); // (20*5 + 5*4) / 25 = 4.8
        $this->assertSame(5, $response->json('best_rating'));
        $this->assertSame(1, $response->json('worst_rating'));
    }

    public function test_summary_excludes_pending_and_rejected_reviews(): void
    {
        $this->makeReview(['rating' => 5, 'is_approved' => true, 'status' => Review::STATUS_APPROVED]);
        $this->makeReview(['rating' => 1, 'is_approved' => false, 'status' => Review::STATUS_PENDING]);
        $this->makeReview(['rating' => 1, 'is_approved' => false, 'status' => Review::STATUS_REJECTED]);

        $response = $this->getJson('/api/reviews/summary?lang=en');

        $this->assertSame(1, $response->json('review_count'));
        // Whole-number floats (5.0) round-trip through JSON as a bare integer, so this compares
        // numeric value rather than exact PHP type.
        $this->assertEquals(5.0, $response->json('rating_value'));
    }

    public function test_summary_is_scoped_per_language(): void
    {
        $this->makeReview(['lang' => 'en', 'rating' => 5]);
        $this->makeReview(['lang' => 'en', 'rating' => 5]);
        $this->makeReview(['lang' => 'fa', 'rating' => 1]);

        $en = $this->getJson('/api/reviews/summary?lang=en');
        $fa = $this->getJson('/api/reviews/summary?lang=fa');

        $this->assertSame(2, $en->json('review_count'));
        $this->assertEquals(5.0, $en->json('rating_value'));
        $this->assertSame(1, $fa->json('review_count'));
        $this->assertEquals(1.0, $fa->json('rating_value'));
    }

    public function test_summary_does_not_fall_back_to_the_default_language_when_empty(): void
    {
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => true, 'is_active' => true]);
        $this->makeReview(['lang' => 'fa', 'rating' => 5]);

        // Unlike GET /api/reviews, borrowing the default language's rating for a page that has
        // zero reviews of its own would be an inaccurate claim about that specific page.
        $response = $this->getJson('/api/reviews/summary?lang=de');

        $response->assertOk();
        $this->assertSame('de', $response->json('lang'));
        $this->assertSame(0, $response->json('review_count'));
        $this->assertNull($response->json('rating_value'));
    }

    public function test_summary_defaults_to_the_sites_default_language_when_lang_is_omitted(): void
    {
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => true, 'is_active' => true]);
        $this->makeReview(['lang' => 'fa', 'rating' => 3]);

        $response = $this->getJson('/api/reviews/summary');

        $this->assertSame('fa', $response->json('lang'));
        $this->assertSame(1, $response->json('review_count'));
    }

    public function test_summary_reports_zero_and_null_when_reviews_are_globally_disabled(): void
    {
        $this->makeReview(['rating' => 5]);
        app(ReviewsSettingsService::class)->setEnabled(false);

        $response = $this->getJson('/api/reviews/summary?lang=en');

        $response->assertOk();
        $this->assertFalse($response->json('enabled'));
        $this->assertSame(0, $response->json('review_count'));
        $this->assertNull($response->json('rating_value'));
    }

    public function test_summary_is_open_to_any_origin(): void
    {
        $response = $this->getJson('/api/reviews/summary?lang=en');

        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_the_main_list_endpoint_includes_the_same_aggregate(): void
    {
        foreach ([5, 5, 5, 4] as $rating) {
            $this->makeReview(['rating' => $rating]);
        }

        $response = $this->getJson('/api/reviews?lang=en&limit=2');

        // The aggregate must reflect all 4 approved reviews even though only 2 are returned.
        $this->assertCount(2, $response->json('reviews'));
        $this->assertSame(4, $response->json('aggregate.review_count'));
        $this->assertSame(4.8, $response->json('aggregate.rating_value'));
    }
}
