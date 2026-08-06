<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Which of the six PanelSection areas this user can see - a JSON array of section
            // keys (e.g. ["giveaway","telegram"]), ignored entirely for a super admin. Plain
            // column rather than a permissions package/pivot table: this app ships as a
            // file-swap zip with no composer/shell access on the server, so a real dependency
            // here would mean either shipping the entire multi-GB vendor/ tree in every future
            // update or hand-patching Composer's autoload registry on a host nobody can debug
            // remotely if it goes wrong - a fixed set of six booleans doesn't need either.
            $table->json('granted_sections')->nullable()->after('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('granted_sections');
        });
    }
};
