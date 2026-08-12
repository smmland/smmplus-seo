<?php

namespace Tests\Feature;

use App\Models\Review;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// The reviews table's own migration seeds 20 starter reviews (see its up() method) so production
// gets real homepage content the moment this ships - RefreshDatabase re-runs that migration for
// every test here, so each test clears them first rather than assuming an empty table.
class ReviewsApiTest extends TestCase
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
            'is_approved' => true,
            'sort_order' => 0,
        ], $overrides));
    }

    public function test_only_approved_reviews_are_returned(): void
    {
        $this->makeReview(['author_name' => 'Approved One', 'is_approved' => true, 'sort_order' => 0]);
        $this->makeReview(['author_name' => 'Pending One', 'is_approved' => false, 'sort_order' => 1]);

        $response = $this->getJson('/api/reviews');

        $response->assertOk();
        $names = collect($response->json('reviews'))->pluck('author_name');

        $this->assertTrue($names->contains('Approved One'));
        $this->assertFalse($names->contains('Pending One'));
    }

    public function test_reviews_are_returned_in_manual_sort_order(): void
    {
        $this->makeReview(['author_name' => 'Third', 'sort_order' => 2]);
        $this->makeReview(['author_name' => 'First', 'sort_order' => 0]);
        $this->makeReview(['author_name' => 'Second', 'sort_order' => 1]);

        $response = $this->getJson('/api/reviews');

        $this->assertSame(
            ['First', 'Second', 'Third'],
            collect($response->json('reviews'))->pluck('author_name')->all(),
        );
    }

    public function test_limit_defaults_to_twenty_and_is_capped_at_fifty(): void
    {
        Review::factory()->count(25)->create(['is_approved' => true]);

        $default = $this->getJson('/api/reviews');
        $this->assertCount(20, $default->json('reviews'));

        $capped = $this->getJson('/api/reviews?limit=999');
        $this->assertCount(25, $capped->json('reviews'));

        $small = $this->getJson('/api/reviews?limit=3');
        $this->assertCount(3, $small->json('reviews'));
    }

    public function test_response_includes_country_flag_and_avatar_url(): void
    {
        $this->makeReview([
            'author_name' => 'Flagged',
            'country_name' => 'Iran',
            'country_code' => 'ir',
            'avatar_path' => 'reviews/avatars/pic.jpg',
        ]);

        $response = $this->getJson('/api/reviews');
        $review = $response->json('reviews.0');

        $this->assertSame('🇮🇷', $review['country_flag']);
        $this->assertStringContainsString('/review-avatars/reviews/avatars/pic.jpg', $review['avatar_url']);
    }

    public function test_response_is_open_to_any_origin(): void
    {
        $this->makeReview();

        $response = $this->getJson('/api/reviews');

        $response->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_a_review_without_a_country_code_has_a_null_flag(): void
    {
        $this->makeReview(['author_name' => 'No Country']);

        $response = $this->getJson('/api/reviews');

        $this->assertNull($response->json('reviews.0.country_flag'));
    }
}
