<?php

namespace Tests\Feature;

use App\Models\Language;
use App\Models\Review;
use App\Services\ReviewsSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReviewSubmissionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Review::query()->delete();
    }

    private function fakeGeolocation(string $countryCode, string $countryName): void
    {
        Http::fake([
            'ip-api.com/*' => Http::response([
                'status' => 'success',
                'countryCode' => $countryCode,
                'country' => $countryName,
            ], 200),
        ]);
    }

    private function submit(array $overrides = [], array $headers = []): \Illuminate\Testing\TestResponse
    {
        // The test client's own IP is loopback, which IpGeolocationService deliberately skips
        // (no point geolocating a private/reserved address) - a CF-Connecting-IP header with a
        // real-looking public IP is what GatewayClient::resolveIp() actually reads behind
        // Cloudflare in production, so this is what exercises the real detection path here.
        return $this->postJson('/api/reviews', array_merge([
            'author_name' => 'Jane Doe',
            'rating' => 5,
            'body' => 'Great service, fast delivery.',
        ], $overrides), array_merge(['Origin' => 'https://smm.plus', 'CF-Connecting-IP' => '8.8.8.8'], $headers));
    }

    public function test_a_valid_submission_is_saved_as_unapproved(): void
    {
        $this->fakeGeolocation('FR', 'France');

        $response = $this->submit(['author_name' => 'Camille', 'related_service' => 'Instagram Followers']);

        $response->assertOk();
        $this->assertTrue($response->json('ok'));

        $review = Review::query()->where('author_name', 'Camille')->first();
        $this->assertNotNull($review);
        $this->assertFalse($review->is_approved);
        $this->assertSame('Instagram Followers', $review->related_service);
    }

    public function test_a_submitted_review_does_not_appear_in_the_public_list_until_approved(): void
    {
        $this->fakeGeolocation('FR', 'France');

        $this->submit(['author_name' => 'Camille']);

        $response = $this->getJson('/api/reviews?lang=fr');
        $names = collect($response->json('reviews'))->pluck('author_name');

        $this->assertFalse($names->contains('Camille'));
    }

    public function test_country_and_language_are_auto_detected_from_ip_not_the_caller(): void
    {
        $this->fakeGeolocation('DE', 'Germany');

        $response = $this->submit(['author_name' => 'Klaus', 'lang' => 'zh', 'country_name' => 'Spoofed']);

        $review = Review::query()->where('author_name', 'Klaus')->first();
        $this->assertSame('de', $review->lang, 'lang must come from the IP lookup, never a client-supplied field.');
        $this->assertSame('Germany', $review->country_name);
        $this->assertSame('DE', $review->country_code);
        $this->assertSame('de', $response->json('lang'));
    }

    public function test_falls_back_to_the_sites_default_language_when_the_country_has_no_mapping(): void
    {
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => true, 'is_active' => true]);
        $this->fakeGeolocation('IS', 'Iceland'); // not in the country->language map

        $this->submit(['author_name' => 'Unmapped']);

        $review = Review::query()->where('author_name', 'Unmapped')->first();
        $this->assertSame('fa', $review->lang);
    }

    public function test_falls_back_gracefully_when_geolocation_fails(): void
    {
        Http::fake(['ip-api.com/*' => Http::response([], 500)]);

        $response = $this->submit(['author_name' => 'NoGeo']);

        $response->assertOk();
        $review = Review::query()->where('author_name', 'NoGeo')->first();
        $this->assertNull($review->country_code);
        $this->assertNull($review->country_name);
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $response = $this->submit(['author_name' => '']);

        $response->assertStatus(422);
        $this->assertFalse($response->json('ok'));
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $response = $this->submit(['rating' => 7]);

        $response->assertStatus(422);
    }

    public function test_a_second_submission_from_the_same_ip_within_two_hours_is_rejected(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'First Submitter'])->assertOk();

        $response = $this->submit(['author_name' => 'Second Attempt']);

        $response->assertStatus(429);
        $this->assertNull(Review::query()->where('author_name', 'Second Attempt')->first());
    }

    public function test_a_submission_is_allowed_again_after_the_two_hour_window(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'First Submitter'])->assertOk();

        $this->travel(3)->hours();

        $response = $this->submit(['author_name' => 'Second Submitter']);

        $response->assertOk();
        $this->assertNotNull(Review::query()->where('author_name', 'Second Submitter')->first());
    }

    public function test_submission_is_rejected_when_reviews_are_disabled(): void
    {
        $this->fakeGeolocation('US', 'United States');
        app(ReviewsSettingsService::class)->setEnabled(false);

        $response = $this->submit();

        $response->assertStatus(403);
        $this->assertSame(0, Review::query()->count());
    }

    public function test_submission_from_a_disallowed_origin_is_rejected(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $response = $this->submit([], ['Origin' => 'https://evil.example.com']);

        $response->assertStatus(403);
        $this->assertSame(0, Review::query()->count());
    }
}
