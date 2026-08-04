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
        Schema::create('service_translation_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('service_key');
            $table->string('target_lang');
            $table->string('status'); // queued, running, done, failed
            $table->text('message')->nullable();
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->unique(['service_key', 'target_lang']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_translation_jobs');
    }
};
