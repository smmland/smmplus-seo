<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_blocked_ips', function (Blueprint $table) {
            $table->timestamp('cpanel_synced_at')->nullable()->after('offense_count');
            $table->text('cpanel_sync_error')->nullable()->after('cpanel_synced_at');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_blocked_ips', function (Blueprint $table) {
            $table->dropColumn(['cpanel_synced_at', 'cpanel_sync_error']);
        });
    }
};
