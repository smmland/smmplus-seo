<?php

namespace Tests\Feature;

use App\Filament\Resources\CatalogServiceResource\Pages\ListCatalogServices;
use App\Models\CatalogService;
use App\Models\Language;
use App\Models\ServiceTranslation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

// The Catalog Services list has no per-language rows (catalog_services is language-agnostic) -
// the Language filter instead just picks which language's Service Translation the
// Name/Description/Status columns display for the same rows, per the admin's request to see
// which language a service is actually translated into and switch between them.
class CatalogServiceResourceLanguageColumnTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsSuperAdmin(): void
    {
        $this->actingAs(User::factory()->create(['is_super_admin' => true]));
    }

    public function test_the_name_column_shows_the_raw_catalog_name_for_the_default_language_by_default(): void
    {
        $this->actingAsSuperAdmin();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        $service = CatalogService::create(['service_id' => '1', 'name' => 'Raw API Name', 'available' => true]);

        Livewire::test(ListCatalogServices::class)
            ->assertTableColumnStateSet('name', 'Raw API Name', $service)
            ->assertTableColumnStateSet('translation_status', 'Source', $service);
    }

    public function test_switching_the_language_filter_shows_that_languages_translation(): void
    {
        $this->actingAsSuperAdmin();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => false, 'is_active' => true]);
        $service = CatalogService::create(['service_id' => '1', 'name' => 'Raw API Name', 'available' => true]);
        ServiceTranslation::create([
            'service_key' => '1', 'lang' => 'fa', 'title' => 'اسم فارسی', 'description_text' => 'توضیحات',
            'is_translated' => true, 'translated_at' => now(),
        ]);

        Livewire::test(ListCatalogServices::class)
            ->filterTable('lang', 'fa')
            ->assertTableColumnStateSet('name', 'اسم فارسی', $service)
            ->assertTableColumnStateSet('description', 'توضیحات', $service)
            ->assertTableColumnStateSet('translation_status', 'Translated', $service);
    }

    public function test_a_language_with_no_translation_yet_falls_back_to_the_raw_name_and_flags_not_queued(): void
    {
        $this->actingAsSuperAdmin();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'ar', 'name' => 'Arabic', 'is_default' => false, 'is_active' => true]);
        $service = CatalogService::create(['service_id' => '1', 'name' => 'Raw API Name', 'available' => true]);

        Livewire::test(ListCatalogServices::class)
            ->filterTable('lang', 'ar')
            ->assertTableColumnStateSet('name', 'Raw API Name', $service)
            ->assertTableColumnStateSet('translation_status', 'Not queued', $service);
    }

    public function test_a_translation_row_that_still_only_mirrors_the_default_language_is_pending(): void
    {
        $this->actingAsSuperAdmin();
        Language::create(['code' => 'en', 'name' => 'English', 'is_default' => true, 'is_active' => true]);
        Language::create(['code' => 'fa', 'name' => 'Persian', 'is_default' => false, 'is_active' => true]);
        $service = CatalogService::create(['service_id' => '1', 'name' => 'Raw API Name', 'available' => true]);
        // Seeded (CatalogSyncService), not yet AI-translated - is_translated still null.
        ServiceTranslation::create(['service_key' => '1', 'lang' => 'fa', 'title' => 'Raw API Name']);

        Livewire::test(ListCatalogServices::class)
            ->filterTable('lang', 'fa')
            ->assertTableColumnStateSet('translation_status', 'Pending', $service);
    }
}
