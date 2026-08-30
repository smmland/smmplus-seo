<?php

namespace Tests\Feature;

use App\Models\CatalogService;
use App\Models\GatewayUpstream;
use App\Models\LandingServiceCategory;
use App\Models\ServiceTranslation;
use App\Services\CatalogSettingsService;
use App\Services\CatalogSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// CatalogSyncService is what keeps catalog_services (GET /api/services's data source) matching
// smm.plus's own customer API (action=services, per the documented contract at
// https://smm.plus/api) - real retail price/min/max, not the HTML scraper's
// name/description-only catalog. The base URL/key come from an existing Free Service Gateway >
// API Server (GatewayUpstream) the admin picks on SEO Settings, not a separate credential typed
// here.
class CatalogSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function selectUpstream(string $baseUrl = 'https://smm.plus/api/v2', string $apiKey = 'secret-key'): void
    {
        $upstream = GatewayUpstream::create([
            'name' => 'SMM Plus Main',
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'is_active' => true,
        ]);

        app(CatalogSettingsService::class)->setUpstreamId($upstream->id);
    }

    public function test_sync_fails_without_an_api_server_selected(): void
    {
        $result = app(CatalogSyncService::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API server', $result['error']);
    }

    public function test_sync_fails_when_the_selected_upstream_is_inactive(): void
    {
        $upstream = GatewayUpstream::create([
            'name' => 'Disabled', 'base_url' => 'https://smm.plus/api/v2', 'api_key' => 'k', 'is_active' => false,
        ]);
        app(CatalogSettingsService::class)->setUpstreamId($upstream->id);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('API server', $result['error']);
    }

    public function test_sync_upserts_services_from_the_documented_response_shape(): void
    {
        $this->selectUpstream();

        Http::fake([
            'https://smm.plus/api/v2' => Http::response([
                ['service' => 1, 'name' => 'Followers', 'type' => 'Default', 'category' => 'First Category', 'rate' => '0.90', 'min' => '50', 'max' => '10000', 'refill' => true, 'cancel' => true],
                ['service' => 2, 'name' => 'Comments', 'type' => 'Custom Comments', 'category' => 'Second Category', 'rate' => '8', 'min' => '10', 'max' => '1500', 'refill' => false, 'cancel' => true],
            ], 200),
        ]);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(2, $result['added']);
        $this->assertSame(0, $result['changed']);

        $service = CatalogService::query()->where('service_id', '1')->first();
        $this->assertSame('Followers', $service->name);
        $this->assertSame('First Category', $service->category);
        $this->assertSame('0.90', $service->rate);
        $this->assertSame(50, $service->min);
        $this->assertSame(10000, $service->max);
        $this->assertTrue($service->refill);
        $this->assertTrue($service->cancel);
        $this->assertTrue($service->available);

        Http::assertSent(fn ($request) => $request['action'] === 'services' && $request['key'] === 'secret-key');
    }

    public function test_a_service_missing_from_a_later_sync_is_marked_unavailable_not_deleted(): void
    {
        $this->selectUpstream();

        Http::fake(['https://smm.plus/api/v2' => Http::sequence()
            ->push([
                ['service' => 1, 'name' => 'Followers', 'category' => 'Cat', 'rate' => '1', 'min' => '1', 'max' => '2', 'refill' => false, 'cancel' => false],
                ['service' => 2, 'name' => 'Comments', 'category' => 'Cat', 'rate' => '1', 'min' => '1', 'max' => '2', 'refill' => false, 'cancel' => false],
            ], 200)
            ->push([
                ['service' => 1, 'name' => 'Followers', 'category' => 'Cat', 'rate' => '1', 'min' => '1', 'max' => '2', 'refill' => false, 'cancel' => false],
            ], 200),
        ]);
        app(CatalogSyncService::class)->sync();

        $result = app(CatalogSyncService::class)->sync();

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['unavailable']);
        $this->assertTrue(CatalogService::query()->where('service_id', '1')->value('available'));
        $this->assertFalse((bool) CatalogService::query()->where('service_id', '2')->value('available'));
        $this->assertSame(2, CatalogService::query()->count(), 'the unavailable row is kept, not deleted');
    }

    public function test_sync_reports_failure_without_touching_the_cache_on_an_empty_response(): void
    {
        $this->selectUpstream();

        Http::fake(['https://smm.plus/api/v2' => Http::sequence()
            ->push([
                ['service' => 1, 'name' => 'Followers', 'category' => 'Cat', 'rate' => '1', 'min' => '1', 'max' => '2', 'refill' => false, 'cancel' => false],
            ], 200)
            ->push([], 200),
        ]);
        app(CatalogSyncService::class)->sync();

        $result = app(CatalogSyncService::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertTrue(CatalogService::query()->where('service_id', '1')->value('available'));
    }

    public function test_sync_reports_failure_on_an_error_response(): void
    {
        $this->selectUpstream();

        Http::fake(['https://smm.plus/api/v2' => Http::response(['error' => 'Invalid API key'], 200)]);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertFalse($result['ok']);
        $this->assertSame('Invalid API key', $result['error']);
    }

    public function test_sync_seeds_a_default_language_translation_row_for_a_matched_service(): void
    {
        $this->selectUpstream();
        LandingServiceCategory::create([
            'slug' => 'premium_botstart', 'label' => 'Premium BotStart',
            'match_field' => LandingServiceCategory::MATCH_FIELD_CATEGORY, 'match_text' => 'BotStart', 'is_active' => true,
        ]);

        Http::fake(['https://smm.plus/api/v2' => Http::response([
            ['service' => 1, 'name' => 'شروع ربات پرمیوم', 'category' => 'Telegram Premium BotStart', 'rate' => '5', 'min' => '10', 'max' => '1000', 'refill' => true, 'cancel' => false],
            ['service' => 2, 'name' => 'Instagram Followers', 'category' => 'Instagram', 'rate' => '1', 'min' => '10', 'max' => '1000', 'refill' => false, 'cancel' => false],
        ], 200)]);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertSame(1, $result['seededTranslations'], 'only the matched service should be seeded');

        $row = ServiceTranslation::query()->where('service_key', '1')->where('lang', 'en')->first();
        $this->assertNotNull($row);
        $this->assertSame('شروع ربات پرمیوم', $row->title);
        $this->assertSame('Telegram Premium BotStart', $row->category_title);

        $this->assertNull(ServiceTranslation::query()->where('service_key', '2')->where('lang', 'en')->first(), 'the non-matched service is left alone');
    }

    public function test_sync_does_not_overwrite_an_already_scraped_translation_row(): void
    {
        $this->selectUpstream();
        LandingServiceCategory::create([
            'slug' => 'premium_botstart', 'label' => 'Premium BotStart',
            'match_field' => LandingServiceCategory::MATCH_FIELD_CATEGORY, 'match_text' => 'BotStart', 'is_active' => true,
        ]);
        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'en', 'title' => 'Already scraped title', 'description_text' => 'Already scraped description',
        ]);

        Http::fake(['https://smm.plus/api/v2' => Http::response([
            ['service' => 1, 'name' => 'Different API name', 'category' => 'Telegram Premium BotStart', 'rate' => '5', 'min' => '10', 'max' => '1000', 'refill' => true, 'cancel' => false],
        ], 200)]);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertSame(0, $result['seededTranslations']);
        $row = ServiceTranslation::query()->where('service_key', '1')->where('lang', 'en')->first();
        $this->assertSame('Already scraped title', $row->title);
        $this->assertSame('Already scraped description', $row->description_text);
    }

    public function test_sync_seeds_nothing_when_no_active_landing_category_matches(): void
    {
        $this->selectUpstream();

        Http::fake(['https://smm.plus/api/v2' => Http::response([
            ['service' => 1, 'name' => 'Followers', 'category' => 'Cat', 'rate' => '1', 'min' => '1', 'max' => '2', 'refill' => false, 'cancel' => false],
        ], 200)]);

        $result = app(CatalogSyncService::class)->sync();

        $this->assertSame(0, $result['seededTranslations']);
        $this->assertSame(0, ServiceTranslation::query()->count());
    }
}
