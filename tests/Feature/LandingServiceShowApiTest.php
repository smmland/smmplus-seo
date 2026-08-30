<?php

namespace Tests\Feature;

use App\Models\CatalogService;
use App\Models\LandingServiceCategory;
use App\Models\ServiceTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /api/services/{id} - a single already-known service by its real id (e.g. a
// checkout/order-confirmation page that only has the id, not the category slug it belongs to).
// Same field shape as GET /api/services (index), but NOT restricted to services matched by a
// LandingServiceCategory - any currently-available synced service can be looked up here; category
// matching is only used, best-effort, to fill in "category"/"is_geo" when applicable.
class LandingServiceShowApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCatalogService(array $overrides = []): CatalogService
    {
        return CatalogService::create(array_merge([
            'service_id' => '1',
            'name' => 'Premium BotStart',
            'category' => 'Telegram Premium BotStart',
            'rate' => '5.00',
            'min' => 10,
            'max' => 1000,
            'refill' => true,
            'cancel' => false,
            'available' => true,
        ], $overrides));
    }

    private function makeMapping(array $overrides = []): LandingServiceCategory
    {
        return LandingServiceCategory::create(array_merge([
            'slug' => 'premium_botstart',
            'label' => 'Premium BotStart',
            'match_field' => LandingServiceCategory::MATCH_FIELD_CATEGORY,
            'match_text' => 'Premium BotStart',
            'geo_keyword' => 'GEO',
            'is_active' => true,
        ], $overrides));
    }

    public function test_returns_the_documented_fields_for_a_matched_service(): void
    {
        $this->makeMapping();
        $this->makeCatalogService();

        $response = $this->getJson('/api/services/1');

        $response->assertOk();
        $this->assertSame('premium_botstart', $response->json('category'));
        $service = $response->json('service');
        $this->assertSame('1', $service['id']);
        $this->assertSame('Premium BotStart', $service['name']);
        $this->assertSame('5.00', $service['rate']);
        $this->assertSame('$5.00 / 1000', $service['rate_formatted']);
        $this->assertSame(10, $service['min']);
        $this->assertSame(1000, $service['max']);
        $this->assertTrue($service['refill']);
        $this->assertFalse($service['cancel']);
        $this->assertFalse($service['is_geo']);
        $this->assertNull($service['start_source']);
        $this->assertArrayNotHasKey('average_time', $service);
    }

    public function test_unknown_service_id_returns_404(): void
    {
        $response = $this->getJson('/api/services/999');

        $response->assertStatus(404);
        $this->assertFalse($response->json('ok'));
    }

    public function test_an_unavailable_service_returns_404(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['available' => false]);

        $response = $this->getJson('/api/services/1');

        $response->assertStatus(404);
    }

    public function test_a_service_not_matched_by_any_landing_category_is_still_returned(): void
    {
        $this->makeCatalogService(['category' => 'Instagram Followers']);
        $this->makeMapping(); // matches "Premium BotStart", not "Instagram Followers"

        $response = $this->getJson('/api/services/1');

        $response->assertOk();
        $this->assertNull($response->json('category'));
        $this->assertNull($response->json('service.is_geo'));
    }

    public function test_a_service_with_no_landing_categories_configured_at_all_is_still_returned(): void
    {
        $this->makeCatalogService();

        $response = $this->getJson('/api/services/1');

        $response->assertOk();
        $this->assertNull($response->json('category'));
    }

    public function test_an_inactive_landing_category_is_ignored_but_the_service_is_still_returned(): void
    {
        $this->makeMapping(['is_active' => false]);
        $this->makeCatalogService();

        $response = $this->getJson('/api/services/1');

        $response->assertOk();
        $this->assertNull($response->json('category'));
    }

    public function test_a_matched_service_still_reports_its_landing_category_and_geo_status(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['category' => 'Telegram Premium BotStart GEO']);

        $response = $this->getJson('/api/services/1');

        $response->assertOk();
        $this->assertSame('premium_botstart', $response->json('category'));
        $this->assertTrue($response->json('service.is_geo'));
    }

    public function test_lang_resolves_the_translation_when_available_and_falls_back_otherwise(): void
    {
        $this->makeMapping();
        $this->makeCatalogService();
        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'fa', 'title' => 'شروع ربات پرمیوم', 'description_text' => 'توضیحات فارسی',
        ]);

        $fa = $this->getJson('/api/services/1?lang=fa');
        $this->assertSame('شروع ربات پرمیوم', $fa->json('service.name'));
        $this->assertSame('توضیحات فارسی', $fa->json('service.description'));

        $de = $this->getJson('/api/services/1?lang=de');
        $this->assertSame('Premium BotStart', $de->json('service.name'));
        $this->assertNull($de->json('service.description'));
    }

    public function test_source_label_is_only_exposed_when_an_admin_has_set_it(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['source_label' => 'Telegram Search']);

        $response = $this->getJson('/api/services/1');

        $this->assertSame('Telegram Search', $response->json('service.start_source'));
    }

    public function test_cors_header_is_only_set_for_an_allowed_origin(): void
    {
        $this->makeMapping();
        $this->makeCatalogService();

        $allowed = $this->getJson('/api/services/1', ['Origin' => 'https://smm.plus']);
        $disallowed = $this->getJson('/api/services/1', ['Origin' => 'https://evil.example']);

        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://smm.plus');
        $disallowed->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_a_non_numeric_id_does_not_match_the_route(): void
    {
        $response = $this->getJson('/api/services/abc');

        // Falls through to the {id} route's numeric constraint not matching - a plain 404 from
        // routing itself, not the controller's JSON error shape.
        $response->assertStatus(404);
    }
}
