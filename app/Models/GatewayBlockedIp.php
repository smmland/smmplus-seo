<?php

namespace App\Models;

use App\Models\Concerns\LogsActivity;
use App\Services\CpanelIpBlockerService;
use App\Support\PanelSection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;

class GatewayBlockedIp extends Model
{
    use LogsActivity;

    protected $fillable = [
        'ip', 'note', 'is_active', 'blocked_until', 'offense_count',
        'cpanel_synced_at', 'cpanel_sync_error',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'blocked_until' => 'datetime',
            'cpanel_synced_at' => 'datetime',
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
        $record = static::upsertByIp($ip, function (self $record) use ($reason, $settings) {
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
            ]);
        });

        // Best-effort - our own record above is the source of truth regardless of whether
        // this succeeds. No-ops entirely unless the cPanel IP Blocker integration is configured.
        // Result is written onto the record itself (cpanel_synced_at / cpanel_sync_error) so an
        // admin can confirm from the database whether the integration is actually working,
        // per IP, without needing to dig through log files.
        app(CpanelIpBlockerService::class)->block($record);

        return $record;
    }

    // For triggers where "repeat offender" doesn't apply the way it does to a single abusive
    // IP - a Tor exit node is used by a different person every time it's picked up again, so
    // there's no real offender to escalate against. A flat block-and-expire instead.
    public static function blockForDuration(string $ip, string $reason, int $days): self
    {
        $record = static::upsertByIp($ip, function (self $record) use ($reason, $days) {
            $record->fill([
                'is_active' => true,
                'blocked_until' => now()->addDays($days),
                'note' => "{$reason} (expires in {$days}d)",
            ]);
        });

        app(CpanelIpBlockerService::class)->block($record);

        return $record;
    }

    // firstOrNew()->save() has a window between the "does this IP already have a row" read and
    // the save's INSERT where a second, near-simultaneous block attempt for the same IP (e.g.
    // two requests from the same abusive script landing in the same millisecond) can slip in and
    // insert its own row first - the `ip` column is unique, so whichever of the two saves second
    // hits a UniqueConstraintViolationException instead of a clean update, which used to bubble
    // up as an uncaught 500. If that happens here, the record we're holding is stale (someone
    // else's insert just won the race) - re-fetch the row that actually landed and re-apply the
    // same field logic against it as an update instead, so the second attempt still records its
    // offense/expiry rather than crashing.
    private static function upsertByIp(string $ip, \Closure $fill): self
    {
        $record = static::query()->firstOrNew(['ip' => $ip]);
        $fill($record);

        try {
            $record->save();
        } catch (UniqueConstraintViolationException) {
            $record = static::query()->where('ip', $ip)->firstOrFail();
            $fill($record);
            $record->save();
        }

        return $record;
    }

    public function activityLabel(): string
    {
        return $this->ip;
    }

    public function activitySection(): ?string
    {
        return PanelSection::SECURITY;
    }
}
