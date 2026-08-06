<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GatewayUpstream extends Model
{
    use LogsActivity;

    protected $fillable = ['name', 'base_url', 'api_key', 'is_active'];

    protected $hidden = ['api_key'];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }

    public function services(): HasMany
    {
        return $this->hasMany(GatewayService::class);
    }

    public function activityLabel(): string
    {
        return $this->name;
    }

    public function activitySection(): ?string
    {
        return PanelSection::FREE_SERVICE;
    }
}
