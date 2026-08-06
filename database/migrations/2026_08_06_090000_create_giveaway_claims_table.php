<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per verified "user did the growth action" event - reward delivery itself stays
     * manual (the admin credits the real smm.plus wallet by hand and marks it rewarded here),
     * see GiveawayClaim/GiveawayClaims page.
     */
    public function up(): void
    {
        Schema::create('giveaway_claims', function (Blueprint $table) {
            $table->id();
            $table->string('platform');
            // The smm.plus account's own identity, taken from that panel's server-rendered Twig
            // context - not a local FK, that account data lives in a different system entirely.
            // Keyed on email rather than a numeric account id: nothing in the site's own Twig
            // templates ever references a raw user id (grepped the whole smmplus-website repo -
            // only user['email']/user['username'] show up anywhere), so email is the one
            // identifier we can actually rely on being there, and it's also what the admin looks
            // an account up by when crediting the wallet by hand anyway.
            $table->string('panel_user_email');
            $table->string('panel_username')->nullable();
            // The Telegram/Google account id that performed the verification - kept unique per
            // platform so one social account can't farm claims across many panel accounts.
            $table->string('platform_account_id');
            $table->timestamp('verified_at');
            $table->string('status')->default('verified');
            $table->text('reward_note')->nullable();
            $table->timestamp('rewarded_at')->nullable();
            $table->foreignId('rewarded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['platform', 'panel_user_email']);
            $table->unique(['platform', 'platform_account_id']);
            $table->index(['platform', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('giveaway_claims');
    }
};
