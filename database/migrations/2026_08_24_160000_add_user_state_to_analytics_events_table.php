<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            // Existing rows predate audience classification and must not be mislabeled as guests.
            $table->string('user_state', 20)->default('unknown')->after('device_type');
            $table->index(['site_id', 'user_state', 'occurred_at'], 'analytics_site_user_state_time_idx');
        });
    }

    public function down(): void
    {
        Schema::table('analytics_events', function (Blueprint $table) {
            $table->dropIndex('analytics_site_user_state_time_idx');
            $table->dropColumn('user_state');
        });
    }
};
