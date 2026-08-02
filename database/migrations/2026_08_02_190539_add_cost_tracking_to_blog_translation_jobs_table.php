<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('blog_translation_jobs', function (Blueprint $table) {
            // Recorded once a translation call actually completes (successfully or not - a
            // failed call still burns tokens) - provider/model are stored per-row rather than
            // read back from current AI Settings, since whichever was configured at the time
            // this ran may no longer be what's configured now.
            $table->string('provider')->nullable()->after('message');
            $table->string('model')->nullable()->after('provider');
            $table->unsignedInteger('input_tokens')->nullable()->after('model');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
            // 6 decimal places - at typical per-million-token pricing, a single translation can
            // otherwise round to $0.00 and make the cost breakdown look wrong for small jobs.
            $table->decimal('estimated_cost_usd', 10, 6)->nullable()->after('output_tokens');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('blog_translation_jobs', function (Blueprint $table) {
            $table->dropColumn(['provider', 'model', 'input_tokens', 'output_tokens', 'estimated_cost_usd']);
        });
    }
};
