<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->string('translation_title')->nullable()->after('is_hidden');
            $table->boolean('is_translated')->nullable()->after('translation_title');
            $table->timestamp('translation_checked_at')->nullable()->after('is_translated');
            // True only while is_hidden was set to true BY the translation detector, so the
            // recheck cycle knows it's safe to auto-unhide - and knows to back off the moment
            // an admin changes is_hidden by hand, rather than fighting a manual override.
            $table->boolean('auto_hidden_for_translation')->default(false)->after('translation_checked_at');

            $table->index('is_translated');
            $table->index('auto_hidden_for_translation');
        });
    }

    public function down(): void
    {
        Schema::table('urls', function (Blueprint $table) {
            $table->dropColumn([
                'translation_title',
                'is_translated',
                'translation_checked_at',
                'auto_hidden_for_translation',
            ]);
        });
    }
};
