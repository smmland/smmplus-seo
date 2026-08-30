<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Admin-configurable mapping from a landing page's logical category slug (e.g.
// "premium_botstart", used as GET /api/services?category=...) to a substring match against the
// real catalog_services.category/name text - built this way instead of hardcoding any guessed
// category label, since neither smm.plus's API nor the HTML scraper exposes a stable category
// ID/GEO flag an admin hasn't confirmed yet. The admin fills these in after seeing the real
// synced category strings on the Catalog Services list page.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('landing_service_categories', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->enum('match_field', ['category', 'name'])->default('category');
            $table->string('match_text');
            // Substring checked against the same match_field text to split matched services into
            // GEO vs non-GEO (e.g. "GEO", "Country") - null means this category has no GEO
            // concept at all, so ?geo= is ignored for it rather than matching zero/everything.
            $table->string('geo_keyword')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('landing_service_categories');
    }
};
