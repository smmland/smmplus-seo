<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gateway_blocked_ips', function (Blueprint $table) {
            $table->timestamp('blocked_until')->nullable()->after('is_active');
            $table->unsignedInteger('offense_count')->default(0)->after('blocked_until');
        });
    }

    public function down(): void
    {
        Schema::table('gateway_blocked_ips', function (Blueprint $table) {
            $table->dropColumn(['blocked_until', 'offense_count']);
        });
    }
};
