<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Each user's chosen dashboard card order/visibility - an ordered JSON array of widget keys
     * (see App\Filament\Pages\Dashboard::WIDGET_REGISTRY). A card missing from this list is
     * hidden; null (never customized) means "show every card this user has permission for, in
     * the default order" - same reasoning/shape as users.granted_sections.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('dashboard_widgets')->nullable()->after('granted_sections');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('dashboard_widgets');
        });
    }
};
