<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('panel_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('panel_notification_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('read_at');

            $table->unique(['panel_notification_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_notification_reads');
    }
};
