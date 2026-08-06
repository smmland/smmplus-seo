<?php

namespace App\Filament\Concerns;

use App\Support\PanelSection;
use Filament\Notifications\Notification;

/**
 * For pages whose mutating operations are plain public Livewire methods (wire:click="...")
 * rather than Filament Action objects - there's no single ->visible() hook to gate those the
 * declarative way GiveawayClaims/TelegramQueue's editPostAction/UrlResource's row actions use,
 * so every one of those methods calls assertCanEdit() as its first line instead. A view-only
 * user still sees the same buttons (this doesn't hide them), but every mutation is refused
 * server-side regardless of what the UI shows - the button being visible is a cosmetic gap, not
 * a security one.
 */
trait GuardsSectionEdits
{
    protected function assertCanEdit(string $section): bool
    {
        if (auth()->user()?->hasAccess(PanelSection::key($section, PanelSection::TIER_EDIT)) ?? false) {
            return true;
        }

        Notification::make()
            ->title('No permission')
            ->body('You don\'t have edit access to this section.')
            ->danger()
            ->send();

        return false;
    }
}
