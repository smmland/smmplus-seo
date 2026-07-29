<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GatewayBlockedIp extends Model
{
    protected $fillable = ['ip', 'note', 'is_active', 'blocked_until', 'offense_count'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'blocked_until' => 'datetime',
        ];
    }

    // Manual blocks (blocked_until = null) stay blocked until an admin flips is_active off.
    // Auto-blocks carry a blocked_until, so they lift themselves once it passes even if the
    // cleanup sweep in AutoBlockAbusiveIps hasn't run yet.
    public static function isBlocked(string $ip): bool
    {
        return static::query()
            ->where('ip', $ip)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('blocked_until')->orWhere('blocked_until', '>', now()))
            ->exists();
    }
}
