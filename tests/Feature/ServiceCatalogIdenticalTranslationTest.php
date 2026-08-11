<?php

namespace Tests\Feature;

use App\Models\CategoryTranslation;
use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\Setting;
use App\Services\ServiceCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Reproduces the real bug behind the admin's report: a category/service whose CORRECT
// translation is identical to the default-language text (e.g. "Twitch" - a brand name that
// isn't translated in most languages) could never settle. refreshLanguage() used to treat "own
// translation" as "row text differs from the default text", so an identical-but-real translation
// looked exactly like "not translated yet" - is_translated got reset to false every sync,
// queueMissing() saw !looksTranslated() and requeued it, the AI translated it again (correctly,
// identically), and the cycle repeated forever. Fixed by keying "own translation" off
// translated_at (only ever set when a real translation was saved) instead of a text comparison,
// and treating a fresh, real translation that happens to match the default as confirmed
// immediately - there's no live-site signal that could ever prove it beyond that.
class ServiceCatalogIdenticalTranslationTest extends TestCase
{
    use RefreshDatabase;

    private function seedDefaultLanguage(): void
    {
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'fr', 'name' => 'French', 'is_default' => false, 'is_active' => true]);
        Setting::query()->create(['key' => 'source_sitemap_url', 'value' => 'https://example.com/sitemap.xml']);
    }

    private function servicesPageHtml(): string
    {
        return <<<'HTML'
            <html><body>
            <div id="svcTableWrap">
                <table>
                    <tr class="svc-cat-row" data-filter-table-category-id="212">
                        <span class="svc-cat-label">Twitch</span>
                    </tr>
                    <tr class="svc-tr" data-filter-table-service-id="101" data-filter-table-category-id="212">
                        <td class="svc-name">Twitch</td>
                        <td class="svc-desc">Twitch Followers</td>
                    </tr>
                </table>
            </div>
            </body></html>
            HTML;
    }

    private function fakeDefaultAndFrenchPages(): void
    {
        // The brand name legitimately doesn't change in French - both pages show identical text.
        Http::fake([
            'https://example.com/fr/services' => Http::response($this->servicesPageHtml(), 200),
            'https://example.com/services' => Http::response($this->servicesPageHtml(), 200),
        ]);
    }

    public function test_a_category_translation_identical_to_the_default_settles_as_confirmed(): void
    {
        $this->seedDefaultLanguage();
        $this->fakeDefaultAndFrenchPages();

        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $default = CategoryTranslation::query()->where('category_id', '212')->where('lang', 'en')->first();
        $this->assertNotNull($default);

        // A previously-completed, real AI translation that happens to match the default exactly.
        CategoryTranslation::create([
            'category_id' => '212',
            'lang' => 'fr',
            'title' => 'Twitch',
            'is_translated' => true,
            'translated_at' => now(),
            'title_translated_from_hash' => $default->source_title_hash,
        ]);

        app(ServiceCatalogService::class)->refreshLanguage('fr');

        $frRow = CategoryTranslation::query()->where('category_id', '212')->where('lang', 'fr')->first();

        $this->assertTrue($frRow->looksTranslated(), 'A real translation identical to the default should still count as translated.');
        $this->assertNotNull($frRow->live_confirmed_at, 'An identical-to-default translation has nothing further to detect, so it should be confirmed immediately.');
        $this->assertFalse($frRow->needsSiteUpdate(), 'It must not keep asking to be uploaded once confirmed - that is what caused the infinite retranslation loop.');
    }

    public function test_a_service_title_and_description_identical_to_the_default_settle_as_confirmed(): void
    {
        $this->seedDefaultLanguage();
        $this->fakeDefaultAndFrenchPages();

        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $default = ServiceTranslation::query()->where('service_key', '101')->where('lang', 'en')->first();
        $this->assertNotNull($default);

        ServiceTranslation::create([
            'service_key' => '101',
            'lang' => 'fr',
            'title' => 'Twitch',
            'description_text' => 'Twitch Followers',
            'is_translated' => true,
            'translated_at' => now(),
            'description_translated_from_hash' => $default->source_description_hash,
            'is_title_translated' => true,
            'title_translated_at' => now(),
            'title_translated_from_hash' => $default->source_title_hash,
        ]);

        app(ServiceCatalogService::class)->refreshLanguage('fr');

        $frRow = ServiceTranslation::query()->where('service_key', '101')->where('lang', 'fr')->first();

        $this->assertTrue($frRow->looksTranslated());
        $this->assertFalse($frRow->needsSiteUpdate(), 'An identical-to-default description must not loop back to "not translated".');

        $this->assertTrue($frRow->titleLooksTranslated());
        $this->assertFalse($frRow->titleNeedsSiteUpdate(), 'An identical-to-default title must not loop back to "not translated".');
    }

    public function test_a_stale_translation_still_falls_back_to_not_translated_even_if_currently_identical(): void
    {
        $this->seedDefaultLanguage();
        $this->fakeDefaultAndFrenchPages();

        app(ServiceCatalogService::class)->syncDefaultCatalog();

        $default = CategoryTranslation::query()->where('category_id', '212')->where('lang', 'en')->first();

        // Translated against an OLDER version of the source name (hash mismatch) - even though it
        // happens to read "Twitch" right now, it must not be trusted as fresh.
        CategoryTranslation::create([
            'category_id' => '212',
            'lang' => 'fr',
            'title' => 'Twitch',
            'is_translated' => true,
            'translated_at' => now(),
            'title_translated_from_hash' => 'stale-hash-from-a-previous-source-name',
        ]);

        app(ServiceCatalogService::class)->refreshLanguage('fr');

        $frRow = CategoryTranslation::query()->where('category_id', '212')->where('lang', 'fr')->first();

        $this->assertFalse($frRow->looksTranslated(), 'A translation made against a stale source hash must still be treated as needing re-translation.');
    }
}
