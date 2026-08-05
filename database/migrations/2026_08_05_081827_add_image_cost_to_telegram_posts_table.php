<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Kept separate from estimated_cost_usd (the text-generation cost) rather than folded into
     * one combined number, so the AI Costs page can show "how much of this was images" on its
     * own - the two use entirely different pricing (per-token vs. per-image) and different
     * providers can be active for each (image generation always uses OpenAI, see
     * AiSettingsService::getImageModel()).
     */
    public function up(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->decimal('image_cost_usd', 10, 6)->nullable()->after('estimated_cost_usd');
        });
    }

    public function down(): void
    {
        Schema::table('telegram_posts', function (Blueprint $table) {
            $table->dropColumn('image_cost_usd');
        });
    }
};
