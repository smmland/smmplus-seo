<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The category counterpart to service_translation_jobs - no `field` column, since a category
     * has only one translatable thing (its name), unlike a service (title + description).
     */
    public function up(): void
    {
        Schema::create('category_translation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('category_id');
            $table->string('target_lang');
            // 'missing' (never translated before) or 'source_changed' (was translated, but the
            // default category name changed since, so a fresh translation was queued) - mirrors
            // service_translation_jobs.trigger.
            $table->string('trigger')->default('missing');
            $table->string('status'); // queued, running, done, failed
            $table->text('message')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->unique(['category_id', 'target_lang']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('category_translation_jobs');
    }
};
