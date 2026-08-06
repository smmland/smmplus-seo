<?php

use App\Support\PanelSection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (array_keys(PanelSection::LABELS) as $section) {
            DB::table('permissions')->updateOrInsert(
                ['name' => PanelSection::permissionKey($section), 'guard_name' => 'web'],
                ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', array_map(
                fn (string $section) => PanelSection::permissionKey($section),
                array_keys(PanelSection::LABELS),
            ))
            ->delete();
    }
};
