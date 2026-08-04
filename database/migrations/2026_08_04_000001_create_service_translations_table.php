<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('service_translations', function (Blueprint $table) {
            $table->id();
            // The stable numeric id the site itself uses (data-filter-table-service-id on
            // https://smm.plus/services) - not a urls.id FK, since a service isn't a standalone
            // page the way a blog post is: every language's version lives on the same shared
            // /services (or /{lang}/services) listing page, not its own URL.
            $table->string('service_key');
            $table->string('lang');
            $table->string('category_id')->nullable();
            $table->string('category_title')->nullable();
            $table->string('title')->nullable();
            // The service description as found on the page for this language - HTML (just <br>
            // line breaks, nothing more complex like a blog article body), so no separate
            // extracted-file storage the way blog content gets - short enough to live directly
            // on the row.
            $table->text('description')->nullable();
            // Plain-text version of the same description (tags stripped) - what
            // ServiceCatalogService actually compares between languages, so a harmless HTML
            // formatting difference (e.g. an extra <br>) never reads as "not translated".
            $table->text('description_text')->nullable();
            // Only meaningful on the default-language row: a hash of that row's own
            // description_text, used to notice when the source description changes so every
            // other language's translation can be flagged stale (is_translated reset to null)
            // instead of silently going out of date forever.
            $table->string('source_description_hash')->nullable();
            // Null until a language's page has actually been fetched and compared - true once
            // its description is confirmed to differ from the default language's (genuinely
            // translated, whether by this tool's AI or already live on the site), false if it
            // still just matches the default.
            $table->boolean('is_translated')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('check_note')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['service_key', 'lang']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_translations');
    }
};
