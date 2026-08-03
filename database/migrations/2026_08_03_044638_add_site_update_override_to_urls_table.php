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
        Schema::table('urls', function (Blueprint $table) {
            // Lets an admin override BlogTranslationDetectionService's live-fetch verdict by
            // hand for one language - needed because comparing just the fetched <title> can
            // false-positive as "translated" on a soft-404 (a site that returns HTTP 200 with
            // some other title instead of a real 404 status for a page that doesn't actually
            // exist), which no automatic heuristic here can fully rule out for every site.
            $table->boolean('site_update_override')->nullable()->after('auto_hidden_for_translation');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('site_update_override');
        });
    }
};
