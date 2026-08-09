<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks whether the "about to send" personal DM preview alert has already gone out for this
     * post - without it, the once-a-minute preview check (TelegramAlertPostPreviewsCommand) would
     * re-alert on every tick for as long as a post sits inside the preview window.
     */
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->timestamp('preview_alert_sent_at')->nullable()->after('scheduled_at');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropColumn('preview_alert_sent_at');
        });
    }
};
