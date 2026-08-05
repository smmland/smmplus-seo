<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Telegram's own numeric id for the message once actually sent - set right after a
     * successful sendPhoto/sendMessage (TelegramSendQueueCommand). Lets
     * TelegramCaptureChannelPostsCommand tell "a channel post we already sent and are tracking"
     * apart from "something posted directly to the channel outside this panel" when it polls
     * getUpdates - both show up as the same channel_post update type, only this id distinguishes
     * them.
     */
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->unsignedBigInteger('telegram_message_id')->nullable()->after('sent_at');
            $table->index('telegram_message_id');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropColumn('telegram_message_id');
        });
    }
};
