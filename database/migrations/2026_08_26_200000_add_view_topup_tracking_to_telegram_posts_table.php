<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->timestamp('views_checked_at')->nullable()->after('views_order_error');
            $table->unsignedBigInteger('observed_views')->nullable()->after('views_checked_at');
            $table->unsignedInteger('views_last_order_quantity')->nullable()->after('observed_views');
            $table->string('views_upstream_order_id')->nullable()->after('views_last_order_quantity');
            $table->index(['status', 'sent_at', 'views_checked_at'], 'telegram_posts_view_check_idx');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropIndex('telegram_posts_view_check_idx');
            $table->dropColumn(['views_checked_at', 'observed_views', 'views_last_order_quantity', 'views_upstream_order_id']);
        });
    }
};
