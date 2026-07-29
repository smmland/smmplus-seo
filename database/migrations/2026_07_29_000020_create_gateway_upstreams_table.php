<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gateway_upstreams', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('base_url');
            $table->text('api_key');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gateway_upstreams');
    }
};
