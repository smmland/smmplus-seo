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
        Schema::table('service_translation_jobs', function (Blueprint $table) {
            // 'missing' (never translated before) or 'source_changed' (was translated, but the
            // default-language description/title changed since, so RefreshServiceCatalogCommand
            // queued a fresh translation) - lets ServiceAiTranslationService mark the row
            // distinctly once done, so an admin can tell "just finished its first translation"
            // apart from "got automatically re-translated because the source text changed".
            $table->string('trigger')->default('missing')->after('field');
        });

        Schema::table('service_translations', function (Blueprint $table) {
            // Set only when a completed translation job's trigger was 'source_changed' - kept
            // separate for description vs title, same as every other status column on this
            // table, so a description-only content change never marks the title as
            // re-translated and vice versa.
            $table->timestamp('description_auto_retranslated_at')->nullable()->after('check_note');
            $table->timestamp('title_auto_retranslated_at')->nullable()->after('title_check_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_translation_jobs', function (Blueprint $table) {
            $table->dropColumn('trigger');
        });

        Schema::table('service_translations', function (Blueprint $table) {
            $table->dropColumn(['description_auto_retranslated_at', 'title_auto_retranslated_at']);
        });
    }
};
