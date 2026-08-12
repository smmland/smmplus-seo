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
        Schema::table('reviews', function (Blueprint $table) {
            // Plain string matching Language::code, same loose-coupling convention as
            // CategoryTranslation/ServiceTranslation/TelegramPost - no FK, since a language can be
            // renamed/removed independently. Defaults to 'fa' so the 20 starter reviews from the
            // previous release (all Persian) backfill correctly without a separate data statement.
            $table->string('lang', 5)->default('fa')->after('id');
            $table->index(['lang', 'is_approved', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['lang', 'is_approved', 'sort_order']);
            $table->dropColumn('lang');
        });
    }
};
