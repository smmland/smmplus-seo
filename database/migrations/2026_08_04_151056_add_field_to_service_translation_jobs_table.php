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
        Schema::table('service_translation_jobs', function (Blueprint $table) {
            // 'description' or 'title' - the same queue/worker (ProcessServiceTranslationQueueCommand,
            // ServiceAiTranslationService) drains both, but as fully separate jobs: translating a
            // service's title never touches its description job (or vice versa), and each has its
            // own queued/running/done/failed lifecycle.
            $table->string('field')->default('description')->after('target_lang');
        });

        Schema::table('service_translation_jobs', function (Blueprint $table) {
            $table->dropUnique(['service_key', 'target_lang']);
            $table->unique(['service_key', 'target_lang', 'field']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_translation_jobs', function (Blueprint $table) {
            $table->dropUnique(['service_key', 'target_lang', 'field']);
            $table->unique(['service_key', 'target_lang']);
            $table->dropColumn('field');
        });
    }
};
