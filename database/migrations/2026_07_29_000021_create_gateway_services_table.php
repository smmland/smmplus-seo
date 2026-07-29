<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gateway_upstream_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->string('label');
            $table->unsignedInteger('upstream_service_id');
            $table->unsignedInteger('min_quantity');
            $table->unsignedInteger('max_quantity');
            $table->unsignedInteger('limit_seconds');
            $table->unsignedInteger('ip_limit');
            $table->unsignedInteger('target_limit');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_services');
    }
};
