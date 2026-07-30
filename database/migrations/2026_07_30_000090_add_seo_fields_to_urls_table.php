<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            // Title/SEO meta live in the database (queryable/editable per row) - only the
            // article body itself is saved as a file (content_extraction_path).
            $table->string('article_title')->nullable()->after('content_extraction_path');
            $table->string('seo_title')->nullable()->after('article_title');
            $table->text('meta_description')->nullable()->after('seo_title');
            $table->text('meta_keywords')->nullable()->after('meta_description');
            $table->string('og_title')->nullable()->after('meta_keywords');
            $table->text('og_description')->nullable()->after('og_title');
            $table->string('twitter_title')->nullable()->after('og_description');
            $table->text('twitter_description')->nullable()->after('twitter_title');

            $table->dropColumn('seo_extraction_path');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn([
                'article_title', 'seo_title', 'meta_description', 'meta_keywords',
                'og_title', 'og_description', 'twitter_title', 'twitter_description',
            ]);
            $table->string('seo_extraction_path')->nullable()->after('content_extraction_path');
        });
    }
};
