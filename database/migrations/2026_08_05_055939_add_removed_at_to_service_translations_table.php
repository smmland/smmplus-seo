<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Only meaningful on default-language rows - set the first time a sync notices a
     * previously-known service is no longer on the source catalog page
     * (ServiceCatalogService::syncDefaultCatalog()), so it's announced to Telegram exactly once
     * instead of every hourly sync, and left alone (not re-announced) if the service reappears.
     */
    public function up(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->timestamp('removed_at')->nullable()->after('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::table('service_translations', function (Blueprint $table) {
            $table->dropColumn('removed_at');
        });
    }
};
