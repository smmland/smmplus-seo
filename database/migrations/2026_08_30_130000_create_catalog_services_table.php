<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Cached copy of smm.plus's own retail catalog (CatalogSyncService, action=services on
// https://{host}/api/v2) - the real, currently-billed price/min/max/refill/cancel for every
// service smm.plus sells its customers, distinct from ServiceTranslation's HTML-scraped
// name/description (which has no pricing at all) and from GatewayUpstream/GatewayService's
// wholesale-supplier catalog for the free-service giveaway. Public landing pages read this
// through GET /api/services rather than smm.plus's live API directly, so a page load never waits
// on (or fails because of) that upstream call.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_services', function (Blueprint $table) {
            $table->id();
            $table->string('service_id')->unique();
            $table->string('name');
            $table->string('type')->nullable();
            $table->string('category')->nullable();
            $table->string('rate')->nullable();
            $table->unsignedInteger('min')->nullable();
            $table->unsignedInteger('max')->nullable();
            $table->boolean('refill')->default(false);
            $table->boolean('cancel')->default(false);
            // True as long as the last sync's response still listed this service - flipped to
            // false (never deleted) the moment a sync completes without seeing it again, so
            // LandingServicesController can simply omit it instead of a landing page ever
            // showing a service smm.plus no longer sells.
            $table->boolean('available')->default(true);
            // Admin-typed only, never inferred from the source data (no field anywhere in
            // smm.plus's API or the scraped HTML actually says where a service's delivery
            // starts) - see LandingServicesController's start_source handling.
            $table->string('source_label')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_services');
    }
};
