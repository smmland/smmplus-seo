<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            // What fetchTitle() actually saw on the last check - HTTP status and, when the
            // fetch was rejected or looked like a bot-block/challenge page rather than real
            // page content, why. Lets an admin see why a row was (mis)classified without
            // needing to reproduce the fetch themselves.
            $table->string('translation_check_note')->nullable()->after('translation_checked_at');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('translation_check_note');
        });
    }
};
