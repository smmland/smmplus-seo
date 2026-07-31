<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->longText('edited_content')->nullable()->after('twitter_description');
            $table->timestamp('edited_content_saved_at')->nullable()->after('edited_content');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn(['edited_content', 'edited_content_saved_at']);
        });
    }
};
