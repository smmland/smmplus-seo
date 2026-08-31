<?php

namespace Tests\Feature;

use App\Models\CatalogService;
use App\Models\Language;
use App\Models\LandingServiceCategory;
use App\Models\ServiceTranslation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// GET /api/services - what lets SEO landing pages (e.g. /telegram-premium-bot-start,
// /telegram-geo-targeted-bot-start) read live price/min/max instead of hardcoding them, filtered
// through an admin-configured LandingServiceCategory mapping rather than any hardcoded/guessed
// category text.
class LandingServicesApiTest extends TestCase
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

    public function test_category_parameter_is_required(): void
    {
        $response = $this->getJson('/api/services');

        $response->assertStatus(400);
        $this->assertFalse($response->json('ok'));
    }

    public function test_unknown_category_returns_404(): void
    {
        $response = $this->getJson('/api/services?category=does_not_exist');

        $response->assertStatus(404);
    }

    public function test_matching_services_are_returned_with_the_documented_fields(): void
    {
        $this->makeMapping();
        $this->makeCatalogService();

        $response = $this->getJson('/api/services?category=premium_botstart');

        $response->assertOk();
        $service = $response->json('services.0');

        $this->assertSame('1', $service['id']);
        $this->assertSame('Premium BotStart', $service['name']);
        $this->assertSame('5.00', $service['rate']);
        $this->assertSame(10, $service['min']);
        $this->assertSame(1000, $service['max']);
        $this->assertFalse($service['is_geo']);
        $this->assertNull($service['start_source']);
        $this->assertArrayNotHasKey('average_time', $service);
    }

    public function test_non_matching_services_are_excluded(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'category' => 'Telegram Premium BotStart']);
        $this->makeCatalogService(['service_id' => '2', 'category' => 'Instagram Followers']);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(1, $response->json('services'));
        $this->assertSame('1', $response->json('services.0.id'));
    }

    public function test_unavailable_services_are_omitted(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'available' => true]);
        $this->makeCatalogService(['service_id' => '2', 'available' => false]);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(1, $response->json('services'));
        $this->assertSame('1', $response->json('services.0.id'));
    }

    public function test_geo_filter_splits_geo_and_non_geo_services(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'category' => 'Telegram Premium BotStart']);
        $this->makeCatalogService(['service_id' => '2', 'category' => 'Telegram Premium BotStart GEO']);

        $nonGeo = $this->getJson('/api/services?category=premium_botstart&geo=false');
        $geo = $this->getJson('/api/services?category=premium_botstart&geo=true');

        $this->assertSame(['1'], collect($nonGeo->json('services'))->pluck('id')->all());
        $this->assertSame(['2'], collect($geo->json('services'))->pluck('id')->all());
    }

    public function test_a_category_with_no_geo_keyword_ignores_the_geo_filter(): void
    {
        $this->makeMapping(['geo_keyword' => null]);
        $this->makeCatalogService(['service_id' => '1', 'category' => 'Telegram Premium BotStart']);

        // is_geo is null (not applicable) for this mapping - a ?geo= filter can only exclude a
        // row confirmed true/false, so it has no effect here and every ?geo= value (or none at
        // all) returns the same, full result.
        $default = $this->getJson('/api/services?category=premium_botstart');
        $geoTrue = $this->getJson('/api/services?category=premium_botstart&geo=true');
        $geoFalse = $this->getJson('/api/services?category=premium_botstart&geo=false');

        $this->assertCount(1, $default->json('services'));
        $this->assertCount(1, $geoTrue->json('services'));
        $this->assertCount(1, $geoFalse->json('services'));
        $this->assertNull($default->json('services.0.is_geo'));
    }

    public function test_name_and_description_use_the_requested_languages_translation_when_available(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'name' => 'Premium BotStart']);

        ServiceTranslation::create([
            'service_key' => '1',
            'lang' => 'fa',
            'title' => 'شروع ربات پرمیوم',
            'description_text' => 'توضیحات فارسی',
        ]);

        $response = $this->getJson('/api/services?category=premium_botstart&lang=fa');

        $this->assertSame('شروع ربات پرمیوم', $response->json('services.0.name'));
        $this->assertSame('توضیحات فارسی', $response->json('services.0.description'));
    }

    public function test_falls_back_to_the_catalogs_own_name_when_no_translation_exists(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'name' => 'Premium BotStart']);

        $response = $this->getJson('/api/services?category=premium_botstart&lang=de');

        $this->assertSame('Premium BotStart', $response->json('services.0.name'));
        $this->assertNull($response->json('services.0.description'));
    }

    public function test_source_label_is_only_exposed_when_an_admin_has_set_it(): void
    {
        $this->makeMapping();
        $this->makeCatalogService(['service_id' => '1', 'source_label' => 'Telegram Search']);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertSame('Telegram Search', $response->json('services.0.start_source'));
    }

    public function test_cors_header_is_only_set_for_an_allowed_origin(): void
    {
        $this->makeMapping();
        $this->makeCatalogService();

        $allowed = $this->getJson('/api/services?category=premium_botstart', ['Origin' => 'https://smm.plus']);
        $disallowed = $this->getJson('/api/services?category=premium_botstart', ['Origin' => 'https://evil.example']);

        $allowed->assertHeader('Access-Control-Allow-Origin', 'https://smm.plus');
        $disallowed->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_an_inactive_category_returns_404(): void
    {
        $this->makeMapping(['is_active' => false]);
        $this->makeCatalogService();

        $response = $this->getJson('/api/services?category=premium_botstart');

        $response->assertStatus(404);
    }

    // The pricing sync's raw category/name text is in whatever single language the upstream
    // account happens to be set to (e.g. Persian) - match_text (typed by the admin, normally in
    // English) must still match via the service's English/default-language translation, not the
    // raw untranslated text, since those two languages can differ.
    public function test_matching_uses_the_english_translation_when_the_raw_catalog_text_is_in_another_language(): void
    {
        $this->makeMapping(['match_text' => 'Premium BotStart']);
        $this->makeCatalogService(['service_id' => '1', 'category' => 'دسته پرمیوم بات استارت']);

        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'en', 'category_title' => 'Telegram Premium BotStart',
        ]);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(1, $response->json('services'));
        $this->assertSame('1', $response->json('services.0.id'));
    }

    public function test_matching_falls_back_to_the_raw_catalog_text_before_any_translation_exists(): void
    {
        $this->makeMapping(['match_text' => 'Premium BotStart']);
        // No ServiceTranslation row yet (freshly synced, not yet translated) - raw text is the
        // only candidate, and it happens to already be in English here.
        $this->makeCatalogService(['service_id' => '1', 'category' => 'Telegram Premium BotStart']);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(1, $response->json('services'));
    }

    public function test_matching_excludes_a_service_whose_raw_text_and_translations_both_miss(): void
    {
        $this->makeMapping(['match_text' => 'Premium BotStart']);
        $this->makeCatalogService(['service_id' => '1', 'category' => 'دسته پرمیوم بات استارت']);
        // No English/default-language translation seeded yet - the raw (Persian) text is the only
        // candidate, and it doesn't contain the English match_text, so this stays unmatched
        // rather than guessing.
        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(0, $response->json('services'));
    }

    public function test_geo_detection_also_uses_the_english_translation(): void
    {
        $this->makeMapping(['match_text' => 'Premium BotStart', 'geo_keyword' => 'GEO']);
        $this->makeCatalogService(['service_id' => '1', 'category' => 'دسته پرمیوم بات استارت جغرافیایی']);

        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'en', 'category_title' => 'Telegram Premium BotStart GEO',
        ]);

        $response = $this->getJson('/api/services?category=premium_botstart&geo=true');

        $this->assertCount(1, $response->json('services'));
        $this->assertTrue($response->json('services.0.is_geo'));
    }

    public function test_matching_also_checks_the_sites_default_language_when_it_differs_from_english(): void
    {
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => true, 'is_active' => true]);

        $this->makeMapping(['match_text' => 'BotStart فارسی']);
        $this->makeCatalogService(['service_id' => '1', 'category' => 'some raw api text']);

        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'fa', 'category_title' => 'دسته BotStart فارسی',
        ]);

        $response = $this->getJson('/api/services?category=premium_botstart');

        $this->assertCount(1, $response->json('services'));
    }
}
