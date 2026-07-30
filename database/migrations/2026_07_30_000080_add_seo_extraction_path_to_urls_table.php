<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            // Relative to the "public" disk, e.g. "blog/{slug}/meta-en.json".
            $table->string('seo_extraction_path')->nullable()->after('content_extraction_path');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn('seo_extraction_path');
        });
    }
};
