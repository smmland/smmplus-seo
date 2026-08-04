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
            // Mirrors is_translated/translated_at/live_confirmed_at/check_note, kept fully
            // separate on purpose - a service's title and description are tracked, translated,
            // and downloaded independently of each other, never mixed into one status or one
            // export file.
            $table->boolean('is_title_translated')->nullable()->after('title');
            $table->timestamp('title_translated_at')->nullable()->after('is_title_translated');
            $table->timestamp('title_live_confirmed_at')->nullable()->after('title_translated_at');
            $table->string('title_check_note')->nullable()->after('title_live_confirmed_at');
            // Only meaningful on the default-language row - mirrors source_description_hash,
            // used to notice when the default title itself changes so every other language's
            // title translation can be flagged stale instead of trusted forever.
            $table->string('source_title_hash')->nullable()->after('source_description_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->dropColumn([
                'is_title_translated', 'title_translated_at', 'title_live_confirmed_at',
                'title_check_note', 'source_title_hash',
            ]);
        });
    }
};
