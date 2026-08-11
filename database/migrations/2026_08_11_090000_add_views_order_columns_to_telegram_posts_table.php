<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tracks the outcome of automatically ordering views for a post once it's actually sent
     * (TelegramPostViewsService) - same visibility discipline as cpanel_sync_error on
     * GatewayBlockedIp: the local record is the source of truth regardless of whether the
     * upstream call succeeds, so the result is written back onto the post itself rather than
     * only living in a log file.
     */
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->timestamp('views_ordered_at')->nullable()->after('error_message');
            $table->text('views_order_error')->nullable()->after('views_ordered_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropColumn(['views_ordered_at', 'views_order_error']);
        });
    }
};
