<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            // Everything below is captured as-is from the frontend and NOT currently used for
            // anything - purely stored so a future check (e.g. "does this user_id really own
            // this order_id/ticket_id, and is it actually eligible for a review") has the data
            // to work with without needing another migration first. None of it is validated
            // against our own systems yet.
            $table->string('frontend_user_id')->nullable()->after('submitted_ip');
            $table->string('frontend_order_id')->nullable()->after('frontend_user_id');
            $table->string('frontend_ticket_id')->nullable()->after('frontend_order_id');
            // The site's own CSRF token, submitted for possible future use verifying the
            // request against the site's own session - meaningless to us on its own since we
            // don't share a session/cookie domain with the site, so nothing here checks it.
            $table->string('frontend_csrf_token')->nullable()->after('frontend_ticket_id');
            // Distinct from submitted_ip (resolved server-side via GatewayClient::resolveIp(),
            // which is what rate-limiting/geolocation actually use) - this is only what the
            // frontend itself claims its visitor's IP is, kept for comparison, never trusted.
            $table->string('reported_ip')->nullable()->after('frontend_csrf_token');
            $table->string('user_agent')->nullable()->after('reported_ip');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn([
                'frontend_user_id', 'frontend_order_id', 'frontend_ticket_id',
                'frontend_csrf_token', 'reported_ip', 'user_agent',
            ]);
        });
    }
};
