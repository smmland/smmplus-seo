<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Until now, a failed AI image generation attempt (bad/missing OpenAI key, a model the
     * account isn't verified for, rate limits, ...) was silently swallowed by
     * TelegramPostGeneratorService::resolveImage() - the post was still created, just with no
     * image and no trace of why, so "I turned image generation on but nothing shows up" had no
     * way to be diagnosed from the panel itself. Separate from the existing error_message column
     * (that one's for send failures, once a post is actually dispatched to Telegram) since the
     * two are unrelated failure points and shouldn't clobber each other.
     */
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->text('image_generation_error')->nullable()->after('image_source');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropColumn('image_generation_error');
        });
    }
};
