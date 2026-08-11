<?php

namespace App\Console\Commands;

use App\Filament\Resources\GatewayBlockedIpResource;
use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Services\PanelNotificationService;
use App\Services\TelegramAlertService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Turns the "am I under attack right now" signal GatewayLiveActivityWidget/GatewayActivityBadge
 * already compute live (rejected requests in the last minute over a threshold) into something an
 * admin gets told about instead of having to notice by looking at the dashboard. Runs every
 * minute; fires once when an attack starts and once when it subsides (not every tick it's still
 * ongoing) by tracking start time in cache - the state itself doubles as "was already notified".
 */
class DetectGatewayAttacksCommand extends Command
{
    protected $signature = 'gateway:detect-attacks';

    protected $description = 'Notifies (in-panel + Telegram) when the Free Service Gateway looks like it\'s under attack, and again once it subsides';

    // Mirrors GatewayLiveActivityWidget/GatewayActivityBadge's own threshold - kept in sync by
    // eye rather than shared, same "small deliberate duplication" already used elsewhere in this
    // app for two near-identical service/category translation pipelines.
    private const BLOCKED_STATUSES = [
        GatewayRequestLog::STATUS_BLOCKED_IP,
        GatewayRequestLog::STATUS_TOR_EXIT_NODE,
        GatewayRequestLog::STATUS_RATE_FLOOD,
        GatewayRequestLog::STATUS_UNREASONABLE_INPUT,
        GatewayRequestLog::STATUS_INVALID_ORIGIN,
    ];

    private const ATTACK_THRESHOLD = 10;

    private const CACHE_KEY = 'gateway_attack_started_at';

    public function handle(TelegramAlertService $alerts, PanelNotificationService $notifications): int
    {
        $since = now()->subMinute();

        $blocked = GatewayRequestLog::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', self::BLOCKED_STATUSES)
            ->count();

        $underAttackNow = $blocked >= self::ATTACK_THRESHOLD;
        $startedAt = $this->startedAt();

        if ($underAttackNow && ! $startedAt) {
            Cache::forever(self::CACHE_KEY, now()->toIso8601String());

            $notifications->notify(
                'security',
                'attack_detected',
                "Possible attack detected - {$blocked}+ requests blocked in the last minute",
                'The gateway\'s own defenses (auto-block, Tor blocking, rate limiting) are actively rejecting them.',
                GatewayBlockedIpResource::getUrl(),
            );
            $alerts->notifyAttackDetected($blocked);

            $this->warn("Attack detected: {$blocked} blocked request(s) in the last minute.");

            return self::SUCCESS;
        }

        if (! $underAttackNow && $startedAt) {
            $minutes = max(1, (int) $startedAt->diffInMinutes(now()));
            $blockedIpsDuringIncident = GatewayBlockedIp::query()->where('created_at', '>=', $startedAt)->count();

            Cache::forget(self::CACHE_KEY);

            $notifications->notify(
                'security',
                'attack_subsided',
                "Attack appears to have subsided after {$minutes} minute(s)",
                "{$blockedIpsDuringIncident} IP(s) were auto-blocked during the incident.",
                GatewayBlockedIpResource::getUrl(),
            );
            $alerts->notifyAttackSubsided($minutes, $blockedIpsDuringIncident);

            $this->info("Attack subsided after {$minutes} minute(s), {$blockedIpsDuringIncident} IP(s) blocked.");
        }

        return self::SUCCESS;
    }

    // Same defensive string-only read as TorExitNodeListService::lastRefreshedAt() - a raw Carbon
    // stored via an older/different code path (or any other unexpected cached shape) degrades to
    // "no attack in progress" instead of erroring on every single tick.
    private function startedAt(): ?Carbon
    {
        $stored = Cache::get(self::CACHE_KEY);

        if (! is_string($stored)) {
            return null;
        }

        try {
            return Carbon::parse($stored);
        } catch (\Throwable) {
            return null;
        }
    }
}
