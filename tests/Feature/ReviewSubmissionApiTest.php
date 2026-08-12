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
            'username' => 'jane_doe123',
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

    public function test_country_is_always_geolocated_never_taken_from_the_caller(): void
    {
        $this->fakeGeolocation('DE', 'Germany');

        $this->submit(['author_name' => 'Klaus', 'country_name' => 'Spoofed']);

        $review = Review::query()->where('author_name', 'Klaus')->first();
        $this->assertSame('Germany', $review->country_name);
        $this->assertSame('DE', $review->country_code);
    }

    public function test_lang_falls_back_to_ip_derived_country_when_nothing_more_direct_is_given(): void
    {
        $this->fakeGeolocation('DE', 'Germany');

        $response = $this->submit(['author_name' => 'Klaus']);

        $review = Review::query()->where('author_name', 'Klaus')->first();
        $this->assertSame('de', $review->lang);
        $this->assertSame('de', $response->json('lang'));
    }

    public function test_an_unrecognized_explicit_lang_is_ignored_in_favor_of_ip_derived_country(): void
    {
        $this->fakeGeolocation('DE', 'Germany');

        // 'zh' isn't configured as an active language in this test, so it must not be trusted -
        // falls through to the next signal instead of landing the review under a language
        // nobody actually serves.
        $response = $this->submit(['author_name' => 'Klaus', 'lang' => 'zh']);

        $review = Review::query()->where('author_name', 'Klaus')->first();
        $this->assertSame('de', $review->lang);
        $this->assertSame('de', $response->json('lang'));
    }

    public function test_an_explicit_recognized_lang_wins_over_ip_derived_country(): void
    {
        Language::create(['code' => 'zh', 'name' => 'Chinese', 'is_default' => false, 'is_active' => true]);
        $this->fakeGeolocation('DE', 'Germany'); // would otherwise resolve to 'de'

        $response = $this->submit(['author_name' => 'Zhang', 'lang' => 'zh']);

        $review = Review::query()->where('author_name', 'Zhang')->first();
        $this->assertSame('zh', $review->lang, 'The frontend already knows which language page the visitor is on - that beats guessing from IP.');
        $this->assertSame('zh', $response->json('lang'));
    }

    public function test_accept_language_header_wins_over_ip_derived_country_when_no_explicit_lang_is_given(): void
    {
        Language::create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => true]);
        $this->fakeGeolocation('DE', 'Germany'); // would otherwise resolve to 'de'

        $response = $this->submit([], ['Accept-Language' => 'fr-FR,fr;q=0.9,en;q=0.5']);

        $review = Review::query()->where('author_name', 'Jane Doe')->first();
        $this->assertSame('fr', $review->lang);
        $this->assertSame('fr', $response->json('lang'));
    }

    public function test_explicit_lang_beats_accept_language_which_beats_ip(): void
    {
        Language::create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => true]);
        Language::create(['code' => 'pl', 'name' => 'Polish', 'is_default' => false, 'is_active' => true]);
        $this->fakeGeolocation('DE', 'Germany'); // lowest priority here

        $response = $this->submit(
            ['lang' => 'pl'],
            ['Accept-Language' => 'fr-FR,fr;q=0.9'], // middle priority - should lose to explicit lang
        );

        $review = Review::query()->where('author_name', 'Jane Doe')->first();
        $this->assertSame('pl', $review->lang);
    }

    public function test_an_unrecognized_accept_language_falls_through_to_ip_derived_country(): void
    {
        $this->fakeGeolocation('DE', 'Germany');

        // None of these are configured as active languages, so Accept-Language must be skipped.
        $response = $this->submit([], ['Accept-Language' => 'xx-XX,yy;q=0.5']);

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

    public function test_username_is_required(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $response = $this->submit(['username' => '']);

        $response->assertStatus(422);
    }

    public function test_the_submitters_username_and_ip_are_stored(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'Tracked User', 'username' => 'tracked_account']);

        $review = Review::query()->where('author_name', 'Tracked User')->first();
        $this->assertSame('tracked_account', $review->submitted_username);
        $this->assertSame('8.8.8.8', $review->submitted_ip);
    }

    public function test_username_and_ip_are_never_exposed_by_the_public_list(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'Tracked User', 'username' => 'tracked_account']);
        Review::query()->where('author_name', 'Tracked User')->update(['is_approved' => true]);

        $response = $this->getJson('/api/reviews?lang=en');
        $review = collect($response->json('reviews'))->firstWhere('author_name', 'Tracked User');

        $this->assertNotNull($review);
        $this->assertArrayNotHasKey('submitted_username', $review);
        $this->assertArrayNotHasKey('submitted_ip', $review);
        $this->assertArrayNotHasKey('username', $review);
    }

    public function test_frontend_context_fields_are_stored_when_present(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit([
            'author_name' => 'Order Reviewer',
            'user_id' => '4821',
            'order_id' => '99310',
            'csrf_token' => 'abc123tokenvalue',
            'ip' => '203.0.113.9',
        ]);

        $review = Review::query()->where('author_name', 'Order Reviewer')->first();
        $this->assertSame('4821', $review->frontend_user_id);
        $this->assertSame('99310', $review->frontend_order_id);
        $this->assertNull($review->frontend_ticket_id);
        $this->assertSame('abc123tokenvalue', $review->frontend_csrf_token);
        $this->assertSame('203.0.113.9', $review->reported_ip, 'The frontend-reported IP must be stored separately from the server-detected one.');
        $this->assertSame('8.8.8.8', $review->submitted_ip, 'The server-resolved IP must stay authoritative regardless of what the frontend reports.');
    }

    public function test_a_numeric_json_user_id_is_accepted_not_just_a_string(): void
    {
        $this->fakeGeolocation('US', 'United States');

        // The site's own templating sends user_id as a raw JSON number on at least one page
        // (reported by the user directly: {"user_id": 29, ...}) - must not 422.
        $response = $this->postJson('/api/reviews', [
            'author_name' => 'Numeric Id User',
            'rating' => 3,
            'body' => 'Great.',
            'username' => 'design',
            'user_id' => 29,
            'order_id' => '5908959',
        ], ['Origin' => 'https://smm.plus', 'CF-Connecting-IP' => '8.8.8.8']);

        $response->assertOk();
        $review = Review::query()->where('author_name', 'Numeric Id User')->first();
        $this->assertSame('29', $review->frontend_user_id);
        $this->assertSame('5908959', $review->frontend_order_id);
    }

    public function test_ticket_id_is_stored_for_the_ticket_page(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'Ticket Reviewer', 'ticket_id' => '7710']);

        $review = Review::query()->where('author_name', 'Ticket Reviewer')->first();
        $this->assertSame('7710', $review->frontend_ticket_id);
        $this->assertNull($review->frontend_order_id);
    }

    public function test_the_real_user_agent_header_is_captured_regardless_of_any_body_field(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->postJson('/api/reviews', [
            'author_name' => 'Agent Test', 'rating' => 5, 'body' => 'Nice.', 'username' => 'agent_tester',
        ], [
            'Origin' => 'https://smm.plus', 'CF-Connecting-IP' => '8.8.8.8', 'User-Agent' => 'TestBrowser/1.0',
        ]);

        $review = Review::query()->where('author_name', 'Agent Test')->first();
        $this->assertSame('TestBrowser/1.0', $review->user_agent);
    }

    public function test_frontend_context_fields_are_optional(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $response = $this->submit(['author_name' => 'Minimal Submitter']);

        $response->assertOk();
        $this->assertNotNull(Review::query()->where('author_name', 'Minimal Submitter')->first());
    }

    public function test_frontend_context_fields_are_never_exposed_by_the_public_list(): void
    {
        $this->fakeGeolocation('US', 'United States');

        $this->submit(['author_name' => 'Context Hidden', 'user_id' => '1', 'order_id' => '2', 'csrf_token' => 'secret']);
        Review::query()->where('author_name', 'Context Hidden')->update(['is_approved' => true]);

        $response = $this->getJson('/api/reviews?lang=en');
        $review = collect($response->json('reviews'))->firstWhere('author_name', 'Context Hidden');

        $this->assertNotNull($review);
        foreach (['user_id', 'order_id', 'ticket_id', 'csrf_token', 'reported_ip', 'user_agent', 'frontend_user_id', 'frontend_order_id', 'frontend_ticket_id', 'frontend_csrf_token'] as $key) {
            $this->assertArrayNotHasKey($key, $review);
        }
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
