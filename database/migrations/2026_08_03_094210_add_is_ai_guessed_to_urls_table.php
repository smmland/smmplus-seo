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
            // True for a row whose source_url was *guessed* by this panel's own tooling
            // (BlogAiTranslationService::saveTranslation(), BlogTranslationDetectionService::
            // checkMissingLanguage()) rather than discovered from a real sitemap entry. Rows like
            // this were never expected to appear in the source sitemap in the first place - once
            // BlogTranslationDetectionService independently confirms one live
            // (translation_checked_at gets set), it was wrongly becoming eligible for normal
            // "not in the sitemap -> deactivate" pruning again on the next sync, silently hiding
            // confirmed translations a few hours after they were made. This flag exempts them
            // from that pruning permanently, regardless of confirmation status.
            $table->boolean('is_ai_guessed')->nullable()->after('is_manual');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('is_ai_guessed');
        });
    }
};
