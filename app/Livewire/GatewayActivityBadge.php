<?php

namespace App\Livewire;

use App\Models\GatewayRequestLog;
use App\Support\PanelSection;
use Livewire\Component;

// Compact header version of GatewayLiveActivityWidget (see that class's own docblock for the
// blocked-vs-total reasoning) - visible from any page, not just the dashboard, so a quick "am I
// still under attack" check doesn't require navigating away from whatever else is being worked
// on. Gated to users who can actually see gateway/security data - unlike the CPU/RAM badge next
// to it, this exposes real traffic numbers, not generic server health.
class GatewayActivityBadge extends Component
{
    private const BLOCKED_STATUSES = [
        GatewayRequestLog::STATUS_BLOCKED_IP,
        GatewayRequestLog::STATUS_TOR_EXIT_NODE,
        GatewayRequestLog::STATUS_RATE_FLOOD,
        GatewayRequestLog::STATUS_UNREASONABLE_INPUT,
        GatewayRequestLog::STATUS_INVALID_ORIGIN,
    ];

    private const ATTACK_THRESHOLD = 10;

    public function render()
    {
        if (! $this->canView()) {
            return view('livewire.gateway-activity-badge', ['total' => null, 'blocked' => null, 'underAttack' => false]);
        }

        $since = now()->subMinute();

        $total = GatewayRequestLog::query()->where('created_at', '>=', $since)->count();
        $blocked = GatewayRequestLog::query()
            ->where('created_at', '>=', $since)
            ->whereIn('status', self::BLOCKED_STATUSES)
            ->count();

        return view('livewire.gateway-activity-badge', [
            'total' => $total,
            'blocked' => $blocked,
            'underAttack' => $blocked >= self::ATTACK_THRESHOLD,
        ]);
    }

    private function canView(): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        return $user->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::FREE_SERVICE))
            || $user->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SECURITY));
    }
}
