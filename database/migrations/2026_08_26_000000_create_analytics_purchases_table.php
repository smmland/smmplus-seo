<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_purchases', function (Blueprint $table) {
            $table->id();
            $table->string('site_id', 50)->default('smm-plus');
            $table->string('external_order_id', 128);
            $table->uuid('last_event_id')->unique();
            $table->string('status', 24);
            $table->decimal('gross_amount', 18, 6);
            $table->decimal('refunded_amount', 18, 6)->default(0);
            $table->char('currency', 3);
            $table->uuid('visitor_id')->nullable();
            $table->uuid('session_id')->nullable();
            $table->string('landing_page', 500)->nullable();
            $table->string('language', 12)->nullable();
            $table->string('source', 100)->nullable();
            $table->string('medium', 100)->nullable();
            $table->string('campaign')->nullable();
            $table->string('device_type', 20)->nullable();
            $table->string('user_state', 20)->default('unknown');
            $table->char('country_code', 2)->nullable();
            $table->timestamp('paid_at');
            $table->timestamp('source_updated_at');
            $table->timestamps();

            $table->unique(['site_id', 'external_order_id'], 'analytics_purchase_order_unique');
            $table->index(['site_id', 'paid_at'], 'analytics_purchase_site_paid_idx');
            $table->index(['site_id', 'status', 'paid_at'], 'analytics_purchase_status_paid_idx');
            $table->index(['site_id', 'session_id'], 'analytics_purchase_session_idx');
        });

        Schema::create('analytics_purchase_events', function (Blueprint $table) {
            $table->uuid('event_id')->primary();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_purchase_events');
        Schema::dropIfExists('analytics_purchases');
    }
};
