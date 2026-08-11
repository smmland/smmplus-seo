<?php

namespace Tests\Feature;

use App\Models\CategoryTranslation;
use App\Models\Language;
use App\Models\Setting;
use App\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

// Reproduces the admin's report: category translations kept getting auto-re-translated even
// though the category name "looked" unchanged. syncDefaultCategories() decides "changed" purely
// from an md5 of the label text - if two loads of the exact same visible name hash differently
// (e.g. one has a non-breaking space where the other has a regular space, a byte-identical
// scenario a human comparing screenshots would never catch), every other language's
// is_translated gets wiped and re-queued on every hourly sync, forever.
class ServiceCatalogCategoryHashStabilityTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefaultLanguage(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Setting::query()->create(['key' => 'source_sitemap_url', 'value' => 'https://example.com/sitemap.xml']);
    }

    private function servicesPageHtml(string $categoryLabel): string
    {
        return <<<HTML
            <html><body>
            <div id="svcTableWrap">
                <table>
                    <tr class="svc-cat-row" data-filter-table-category-id="7">
                        <span class="svc-cat-label">{$categoryLabel}</span>
                    </tr>
                    <tr class="svc-tr" data-filter-table-service-id="101" data-filter-table-category-id="7">
                        <td class="svc-name">Followers</td>
                        <td class="svc-desc">Instant followers.</td>
                    </tr>
                </table>
            </div>
            </body></html>
            HTML;
    }

    // Http::fake(['*' => ...]) called a second time does NOT replace the first stub - both
    // '*' rules stay registered and the first-registered one always wins (Laravel resolves
    // stubCallbacks in registration order, first non-null match). A sequence is the correct way
    // to serve a different response on each successive call to the same URL within one test.
    private function fakeTwoSyncsInOrder(string $firstLabel, string $secondLabel): void
    {
        Http::fake([
            '*' => Http::sequence()
                ->push($this->servicesPageHtml($firstLabel), 200)
                ->push($this->servicesPageHtml($secondLabel), 200),
        ]);
    }

    public function test_a_non_breaking_space_does_not_look_like_a_real_category_name_change(): void
    {
        $this->seedDefaultLanguage();

        // Same visible text both times - a regular space, then a non-breaking space (U+00A0) - a
        // human reading either rendered page sees "Instagram Followers" both times.
        $this->fakeTwoSyncsInOrder('Instagram Followers', "Instagram\u{00A0}Followers");

        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $row = CategoryTranslation::query()->where('category_id', '7')->where('lang', 'en')->first();
        $this->assertNotNull($row);

        // A translated row in another language, to prove it survives the second sync below.
        CategoryTranslation::create([
            'category_id' => '7',
            'lang' => 'fr',
            'title' => 'Abonnés Instagram',
            'is_translated' => true,
            'translated_at' => now(),
            'title_translated_from_hash' => $row->source_title_hash,
        ]);

        Log::spy();
        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $frRow = CategoryTranslation::query()->where('category_id', '7')->where('lang', 'fr')->first();
        $this->assertTrue($frRow->is_translated, 'A cosmetic whitespace difference should not wipe an existing translation.');

        Log::shouldNotHaveReceived('info');
    }

    public function test_a_genuine_category_name_change_still_resets_translations_and_logs_it(): void
    {
        $this->seedDefaultLanguage();

        $this->fakeTwoSyncsInOrder('Instagram Followers', 'Instagram Followers (Premium)');

        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $row = CategoryTranslation::query()->where('category_id', '7')->where('lang', 'en')->first();

        CategoryTranslation::create([
            'category_id' => '7',
            'lang' => 'fr',
            'title' => 'Abonnés Instagram',
            'is_translated' => true,
            'translated_at' => now(),
            'title_translated_from_hash' => $row->source_title_hash,
        ]);

        Log::spy();
        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $frRow = CategoryTranslation::query()->where('category_id', '7')->where('lang', 'fr')->first();
        $this->assertNull($frRow->is_translated, 'A real category rename should still flag other languages for re-check.');

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(function ($message, $context) {
                return $message === 'Category translation: source name hash changed, resetting other-language translations'
                    && $context['category_id'] === '7'
                    && $context['previous_title'] === 'Instagram Followers'
                    && $context['new_title'] === 'Instagram Followers (Premium)';
            });
    }
}
