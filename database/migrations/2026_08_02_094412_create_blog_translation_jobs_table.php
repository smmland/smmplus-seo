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
        Schema::create('blog_translation_jobs', function (Blueprint $table) {
            $table->id();
            // Tracks progress by group_key + target_lang rather than a urls.id FK - the target
            // language's Url row often doesn't exist yet when a translation is queued (that's
            // exactly the "no page exists yet, translate with AI" case), so there's nothing to
            // attach this to on the target side until the job itself creates it.
            $table->string('group_key');
            $table->string('target_lang');
            $table->string('status'); // queued, running, done, failed
            $table->text('message')->nullable();
            $table->timestamps();

            $table->unique(['group_key', 'target_lang']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('blog_translation_jobs');
    }
};
