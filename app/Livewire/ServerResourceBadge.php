<?php

namespace App\Livewire;

use App\Services\ServerResourceService;
use Livewire\Component;

// Small header badge (next to the notification bell) showing the server's current CPU load
// average and RAM usage, polling every 30 seconds. Both numbers are best-effort - see
// ServerResourceService's own docblock for why either can come back unavailable on shared
// hosting - so the badge just omits whichever piece it doesn't have (an empty, invisible badge
// if neither is available) rather than showing a broken/misleading "N/A". The wrapping <div> in
// the view is always rendered unconditionally - Livewire requires every component to have a
// single root HTML tag present no matter what, so the "is there anything to show" check has to
// live inside that div, not wrap it.
class ServerResourceBadge extends Component
{
    public function render()
    {
        $service = app(ServerResourceService::class);

        return view('livewire.server-resource-badge', [
            'load' => $service->loadAverage(),
            'memoryPercent' => $service->memoryUsagePercent(),
        ]);
    }
}
