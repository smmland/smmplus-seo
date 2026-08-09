<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Personal DM alert recipients (Telegram Channel > Alerts) - separate from the channel-posting
     * bot's audience entirely. A bot can only message a private chat that has already messaged it
     * first, so each row starts as a pending link (chat_id null, a unique link_token) until the
     * admin opens the generated t.me/<bot>?start=<token> deep link and sends /start - see
     * TelegramCaptureChannelPostsCommand, which polls for that alongside its existing channel-post
     * capture and fills in chat_id/telegram_username/linked_at once it happens.
     */
    public function up(): void
    {
        Schema::create('telegram_alert_recipients', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->string('link_token')->unique();
            $table->string('chat_id')->nullable();
            $table->string('telegram_username')->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_alert_recipients');
    }
};
