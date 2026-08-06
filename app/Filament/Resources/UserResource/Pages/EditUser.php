<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\PanelSection;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    // "sections" isn't a users table column - it's the CheckboxList's transient state, pulled
    // out here (before the record is saved) so it can be applied as permissions afterward.
    private array $sections = [];

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn (): bool => $this->record->id !== auth()->id()),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['sections'] = $this->record->permissions
            ->pluck('name')
            ->map(fn (string $name): string => str_replace('access_', '', $name))
            ->all();

        return $data;
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $this->sections = $data['sections'] ?? [];
        unset($data['sections']);

        return $data;
    }

    protected function afterSave(): void
    {
        // A super admin's checkboxes are hidden (irrelevant, since is_super_admin bypasses them
        // entirely) rather than disabled, so $this->sections is stale/empty for one while toggled
        // on - only actually sync when it isn't, so flipping super admin off later doesn't leave
        // the user with zero sections by accident.
        if ($this->record->is_super_admin) {
            return;
        }

        $this->record->syncPermissions(array_map(
            fn (string $section): string => PanelSection::permissionKey($section),
            $this->sections,
        ));
    }
}
