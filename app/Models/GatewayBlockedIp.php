<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\CpanelIpBlockerService;
use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;

class GatewayBlockedIp extends Model
{
    use LogsActivity;

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

    // Shared by the scheduled volume-based sweep (AutoBlockAbusiveIpsCommand) and any
    // instant single-request block (e.g. an oversized payload) so both paths escalate the
    // block duration identically on repeat offenses rather than each keeping its own copy.
    public static function blockWithEscalation(string $ip, string $reason, \App\Services\GatewaySettingsService $settings): self
    {
        $record = static::query()->firstOrNew(['ip' => $ip]);
        $offenseNumber = ($record->offense_count ?? 0) + 1;
        $hours = min(
            $settings->getAutoBlockMaxHours(),
            $settings->getAutoBlockBaseHours() * ($settings->getAutoBlockMultiplier() ** ($offenseNumber - 1)),
        );

        $record->fill([
            'is_active' => true,
            'blocked_until' => now()->addHours($hours),
            'offense_count' => $offenseNumber,
            'note' => "{$reason} (offense #{$offenseNumber}, {$hours}h)",
        ])->save();

        // Best-effort - our own record above is the source of truth regardless of whether
        // this succeeds. No-ops entirely unless the cPanel IP Blocker integration is configured.
        app(CpanelIpBlockerService::class)->block($ip);

        return $record;
    }

    public function activityLabel(): string
    {
        return $this->ip;
    }

    public function activitySection(): ?string
    {
        return PanelSection::FREE_SERVICE;
    }
}
