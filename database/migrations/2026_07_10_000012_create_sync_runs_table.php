<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['RUNNING', 'SUCCESS', 'FAILED'])->default('RUNNING');
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('total_fetched')->nullable();
            $table->unsignedInteger('added')->nullable();
            $table->unsignedInteger('updated')->nullable();
            $table->unsignedInteger('removed')->nullable();
            $table->text('error_message')->nullable();

            $table->index('started_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
