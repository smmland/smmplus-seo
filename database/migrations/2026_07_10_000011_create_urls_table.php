<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('urls', function (Blueprint $table) {
            $table->id();
            $table->string('source_url')->unique();
            $table->string('path');
            $table->string('lang', 8);
            $table->enum('pattern_type', ['HOME', 'BLOG', 'LANDING', 'STATIC', 'UTILITY', 'OTHER']);
            $table->string('slug');
            $table->string('group_key');
            $table->timestamp('source_lastmod')->nullable();
            $table->float('priority')->nullable();
            $table->string('changefreq')->nullable();
            $table->boolean('is_hidden')->default(false);
            $table->boolean('is_manual')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->index('pattern_type');
            $table->index('group_key');
            $table->index('is_hidden');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('urls');
    }
};
