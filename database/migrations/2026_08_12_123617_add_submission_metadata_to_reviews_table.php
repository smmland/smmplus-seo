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
            // Who/where a publicly-submitted review actually came from - null for reviews an
            // admin writes directly in the panel, always set for ones from POST /api/reviews.
            // Panel-only (never returned by GET /api/reviews) - for moderation/accountability,
            // e.g. spotting one account or IP submitting many reviews.
            $table->string('submitted_username')->nullable()->after('lang');
            $table->string('submitted_ip')->nullable()->after('submitted_username');

            $table->index('submitted_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['submitted_ip']);
            $table->dropColumn(['submitted_username', 'submitted_ip']);
        });
    }
};
