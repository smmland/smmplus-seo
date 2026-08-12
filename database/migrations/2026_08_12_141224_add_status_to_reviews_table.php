<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // is_approved alone can't tell "never looked at yet" apart from "an admin looked at
            // it and rejected it" - both read as false, so a rejected review would keep
            // reappearing in the new moderation queue (ReviewResource\Pages... see
            // PendingReviewResource) forever. status is the real tri-state; is_approved stays as
            // the public API's on/off switch and is kept in sync automatically by
            // Review::booted() whenever code only touches is_approved (every existing call site),
            // so nothing already relying on is_approved needed to change.
            $table->string('status', 20)->default('pending')->after('is_approved');
            $table->index(['status', 'created_at']);
        });

        // Backfill: every row seeded/created before this column existed was already either
        // publicly live (is_approved=1) or never explicitly rejected (is_approved=0) - the
        // latter default to 'pending' already, so only the approved ones need an explicit update.
        DB::table('reviews')->where('is_approved', true)->update(['status' => 'approved']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropColumn('status');
        });
    }
};
