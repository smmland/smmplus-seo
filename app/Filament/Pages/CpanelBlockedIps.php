<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\GuardsSectionEdits;
use App\Models\GatewayBlockedIp;
use App\Services\CpanelIpBlockerService;
use App\Support\PanelSection;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Computed;

/**
 * Shows what cPanel's own IP Blocker actually has blocked, read directly from the .htaccess file
 * it writes to (Security Settings: .htaccess path) - the UAPI module this panel's own blocking
 * (Blocked IPs, Tor bulk-block) talks to only has add_ip/remove_ip, no function to list what's
 * currently blocked, so this is the only way to see cPanel's own view of things (including
 * anything blocked directly in cPanel outside this panel, or drift from a failed sync). Unblocking
 * here calls the real BlockIP::remove_ip UAPI function, same as everywhere else in this panel.
 */
class CpanelBlockedIps extends Page
{
    use GuardsSectionEdits;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'cPanel Blocked IPs';

    protected static ?string $title = 'cPanel Blocked IPs';

    protected static string $view = 'filament.pages.cpanel-blocked-ips';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SECURITY)) ?? false;
    }

    #[Computed]
    public function result(): array
    {
        return app(CpanelIpBlockerService::class)->fetchHtaccessBlockList();
    }

    // Maps each cPanel-side IP to the reason/note this panel blocked it for, when we're the ones
    // who did - purely informational (a manually-added or externally-blocked IP just shows
    // without one), never used to decide what's shown.
    public function noteFor(string $ip): ?string
    {
        return GatewayBlockedIp::query()->where('ip', $ip)->value('note');
    }

    public function refresh(): void
    {
        unset($this->result);
    }

    public function unblock(string $ip, CpanelIpBlockerService $cpanel): void
    {
        if (! $this->assertCanEdit(PanelSection::SECURITY)) {
            return;
        }

        // This list can include IPs blocked directly in cPanel outside this panel entirely, with
        // no local record at all - firstOrNew covers that, saved immediately so the record
        // "exists" before unblock() touches it. cpanel_synced_at is forced to "now" purely to
        // satisfy unblock()'s own guard, which normally requires a record this service previously
        // synced itself - here the .htaccess read is itself the proof the IP is actually blocked
        // at cPanel, which that guard can't otherwise know. Saving first also matters mechanically:
        // Eloquent's update() is a silent no-op on a model that doesn't exist yet, so without this
        // save() neither unblock()'s own success write nor the is_active flip below would ever
        // land for an IP with no prior local record.
        $record = GatewayBlockedIp::query()->firstOrNew(['ip' => $ip]);
        $record->cpanel_synced_at = now();
        $record->save();

        $cpanel->unblock($record);

        unset($this->result);

        // unblock() nulls cpanel_synced_at on this same instance only when the cPanel call
        // actually succeeded - on failure it's left at the "now" set above, so this only ever
        // marks the local record inactive once cPanel confirms the IP is really gone.
        if ($record->cpanel_synced_at !== null) {
            Notification::make()
                ->title("Failed to unblock {$ip} at cPanel")
                ->body($record->cpanel_sync_error ?: 'See the logs for details.')
                ->danger()
                ->send();

            return;
        }

        $record->update(['is_active' => false]);

        Notification::make()
            ->title("Unblocked {$ip}")
            ->success()
            ->send();
    }
}
