<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_request_logs', function (Blueprint $table) {
            $table->id();
            $table->string('ip');
            $table->string('origin')->nullable();
            $table->string('service_slug')->nullable();
            $table->foreignId('gateway_upstream_id')->nullable()->constrained()->nullOnDelete();
            $table->string('target')->nullable();
            $table->string('link')->nullable();
            $table->unsignedInteger('quantity_requested')->nullable();
            $table->unsignedInteger('quantity_allowed')->nullable();
            $table->string('status');
            $table->timestamp('created_at')->useCurrent();

            $table->index('ip');
            $table->index('service_slug');
            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_request_logs');
    }
};
