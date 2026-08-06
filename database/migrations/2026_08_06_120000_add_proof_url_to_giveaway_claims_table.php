<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Self-reported platforms (Trustpilot - no public API can confirm a review is real) need
     * somewhere to store what the user submitted as proof, so the admin has something to actually
     * go check before rewarding a "pending_review" claim - see GiveawayClaim::STATUS_PENDING_REVIEW.
     */
    public function up(): void
    {
        Schema::table('giveaway_claims', function (Blueprint $table) {
            $table->text('proof_url')->nullable()->after('platform_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('giveaway_claims', function (Blueprint $table) {
            $table->dropColumn('proof_url');
        });
    }
};
