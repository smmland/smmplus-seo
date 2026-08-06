<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;

class Language extends Model
{
    use LogsActivity;

    protected $fillable = ['code', 'name', 'is_default', 'is_active', 'sort_order'];

    // Single source of truth for which language codes read right-to-left - used everywhere a
    // preview/editor needs to render (and let you type into) content in the correct direction:
    // extraction's and the AI translator's preview files, the manually-edited preview, and the
    // visual editor's own live iframe.
    private const RTL_CODES = ['ar', 'fa', 'he', 'ur'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public static function isRtl(string $code): bool
    {
        return in_array($code, self::RTL_CODES, true);
    }

    public static function direction(string $code): string
    {
        return self::isRtl($code) ? 'rtl' : 'ltr';
    }

    public function activityLabel(): string
    {
        return "{$this->code} ({$this->name})";
    }

    public function activitySection(): ?string
    {
        return PanelSection::TRANSLATION;
    }
}
