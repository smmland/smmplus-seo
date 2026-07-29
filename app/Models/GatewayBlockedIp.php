<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayBlockedIp extends Model
{
    protected $fillable = ['ip', 'note', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public static function isBlocked(string $ip): bool
    {
        return static::query()->where('ip', $ip)->where('is_active', true)->exists();
    }
}
