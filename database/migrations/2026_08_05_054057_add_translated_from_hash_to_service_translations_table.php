<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Records which source_description_hash/source_title_hash (the default-language row's own
     * hash columns, see 2026_08_04 migrations) a translation was actually made against, set only
     * by ServiceAiTranslationService when it saves a translation. ServiceCatalogService::
     * refreshLanguage() uses this to tell a translation that's still fresh (hash matches the
     * current default) apart from one that's gone stale because the default description/title
     * changed since - a plain "does the stored text differ from the current default" comparison
     * can't do that, since a real translation almost always differs from a different-language
     * default regardless of whether the source has changed underneath it. Left null for rows
     * translated before this column existed, so old data keeps its current (already-verified)
     * protection instead of being reinterpreted as stale.
     */
    public function up(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->string('description_translated_from_hash')->nullable()->after('description_auto_retranslated_at');
            $table->string('title_translated_from_hash')->nullable()->after('title_auto_retranslated_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->dropColumn(['description_translated_from_hash', 'title_translated_from_hash']);
        });
    }
};
