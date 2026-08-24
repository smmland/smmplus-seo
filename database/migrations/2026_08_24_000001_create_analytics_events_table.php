<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('site_id', 50)->default('smm-plus');
            $table->uuid('visitor_id');
            $table->uuid('session_id');
            $table->string('event_name', 64);
            $table->string('page_path', 500);
            $table->string('page_title')->nullable();
            $table->string('page_type', 50)->nullable();
            $table->boolean('is_landing')->default(false);
            $table->string('language', 12)->nullable();
            $table->string('referrer_host')->nullable();
            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('campaign')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->unsignedSmallInteger('viewport_width')->nullable();
            $table->char('country_code', 2)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('scroll_depth')->nullable();
            $table->decimal('metric_value', 12, 4)->nullable();
            $table->string('target', 500)->nullable();
            $table->json('metadata')->nullable();
            // Daily rotating HMAC of the resolved client IP. It helps identify obvious bot
            // floods without retaining the address itself or creating a permanent identifier.
            $table->char('daily_client_hash', 64)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['site_id', 'event_name', 'occurred_at'], 'analytics_site_event_time_idx');
            $table->index(['site_id', 'page_path', 'occurred_at'], 'analytics_site_page_time_idx');
            $table->index(['site_id', 'is_landing', 'occurred_at'], 'analytics_site_landing_time_idx');
            $table->index(['site_id', 'session_id', 'occurred_at'], 'analytics_site_session_time_idx');
            $table->index(['site_id', 'language', 'occurred_at'], 'analytics_site_language_time_idx');
            $table->index(['site_id', 'source', 'occurred_at'], 'analytics_site_source_time_idx');
            $table->index('visitor_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_events');
    }
};
