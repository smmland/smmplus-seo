<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-panel notification center (the bell icon next to the account avatar) - no per-recipient
     * rows here, visibility is computed at read time from the viewing user's own permissions
     * against `category` (which is literally a PanelSection value, e.g. "telegram"/"translation" -
     * see PanelNotification::CATEGORIES), same as every other section-gated feature in this app.
     * Per-user read state lives separately in panel_notification_reads, since different admins
     * have different permissions and shouldn't affect each other's read state.
     */
    public function up(): void
    {
        Schema::create('panel_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('type');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_notifications');
    }
};
