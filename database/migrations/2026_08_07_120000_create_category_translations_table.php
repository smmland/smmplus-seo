<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The title-only counterpart to service_translations, one row per category id per language -
     * a category (e.g. "Instagram Followers") groups many services, and its name is currently
     * copied redundantly onto every one of those services' rows (service_translations.
     * category_title) with no tracking of its own. This table gives the category name the exact
     * same translate/track/confirm-live pipeline service titles already have, keyed on
     * data-filter-table-category-id (the same stable id ServiceCatalogService already reads off
     * the source page) instead of a service_key.
     */
    public function up(): void
    {
        Schema::create('category_translations', function (Blueprint $table) {
            $table->id();
            $table->string('category_id');
            $table->string('lang');
            $table->string('title')->nullable();
            // Only meaningful on the default-language row - a hash of that row's own title, used
            // to notice when the source category name changes so every other language's
            // translation can be flagged stale (is_translated reset to null) instead of trusted
            // forever. Mirrors service_translations.source_title_hash.
            $table->string('source_title_hash')->nullable();
            // Which source_title_hash a translation was actually made against - lets a translation
            // that's still fresh (hash matches the current default) be told apart from one that's
            // gone stale because the default name changed since. Mirrors
            // service_translations.title_translated_from_hash.
            $table->string('title_translated_from_hash')->nullable();
            // Null until a language's page has actually been fetched and compared - true once
            // confirmed to differ from the default language's (genuinely translated, whether by
            // this tool's AI or already live on the site), false if it still just matches.
            $table->boolean('is_translated')->nullable();
            // Set only when ServiceCatalogService's category loop saves an AI translation onto
            // this row, regardless of whether the live site has picked it up yet.
            $table->timestamp('translated_at')->nullable();
            // Set only when the live page's category label was found to genuinely differ from
            // the default language's - the moment a translation was last confirmed actually live.
            $table->timestamp('live_confirmed_at')->nullable();
            // Set only when a completed translation job's trigger was 'source_changed' (the
            // default category name changed and this was automatically re-translated as a result).
            $table->timestamp('auto_retranslated_at')->nullable();
            $table->string('check_note')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translations');
    }
};
