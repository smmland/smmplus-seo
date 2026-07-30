<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->timestamp('content_extracted_at')->nullable()->after('translation_check_note');
            // Relative to the "public" disk, e.g. "blog/{slug}/content-en.html".
            $table->string('content_extraction_path')->nullable()->after('content_extracted_at');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn(['content_extracted_at', 'content_extraction_path']);
        });
    }
};
