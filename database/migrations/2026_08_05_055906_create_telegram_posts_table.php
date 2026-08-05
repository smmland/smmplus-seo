<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shared review queue/log for every AI-drafted Telegram post, both the weekly rolling
     * blog-summary plan (TelegramPostGeneratorService::topUpBlogPlan()) and the near-immediate
     * service catalog change announcements (::draftServiceChanges()) - one table, one status
     * lifecycle, since the admin reviews both the same way regardless of where a post came from.
     */
    public function up(): void
    {
        Schema::create('telegram_posts', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->string('lang');
            // The blog group_key or service service_key this post is about - nullable since not
            // every future post type needs to trace back to one row.
            $table->string('related_key')->nullable();
            $table->string('title');
            $table->text('message_text');
            $table->string('image_path')->nullable();
            $table->string('image_source')->default('none');
            $table->timestamp('scheduled_at');
            $table->string('status')->default('pending');
            $table->timestamp('sent_at')->nullable();
            $table->text('error_message')->nullable();
            $table->string('ai_provider')->nullable();
            $table->string('ai_model')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('estimated_cost_usd', 10, 6)->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index(['type', 'related_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_posts');
    }
};
