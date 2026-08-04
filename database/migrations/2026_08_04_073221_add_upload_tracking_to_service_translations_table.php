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
        Schema::table('service_translations', function (Blueprint $table) {
            // Set only by ServiceAiTranslationService::saveTranslation() - when our own AI
            // translation was last (re)saved onto this row, regardless of whether the live site
            // has picked it up yet.
            $table->timestamp('translated_at')->nullable()->after('is_translated');
            // Set only by ServiceCatalogService::refreshLanguage(), and only when the live page's
            // description was found to genuinely differ from the default language's - i.e. the
            // moment a translation (ours or one made independently on the site) was last
            // confirmed actually live. Compared against translated_at (see
            // ServiceTranslation::needsSiteUpdate()) to tell "translated here, not uploaded yet"
            // apart from "confirmed live on the site".
            $table->timestamp('live_confirmed_at')->nullable()->after('translated_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->dropColumn(['translated_at', 'live_confirmed_at']);
        });
    }
};
