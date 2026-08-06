<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\PanelSection;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    // "sections" isn't a users table column - it's the CheckboxList's transient state, pulled
    // out here (before the record is filled/saved) so it can be applied as permissions afterward.
    private array $sections = [];

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->sections = $data['sections'] ?? [];
        unset($data['sections']);

        return $data;
    }

    protected function afterCreate(): void
    {
        $this->record->syncPermissions(array_map(
            fn (string $section): string => PanelSection::permissionKey($section),
            $this->sections,
        ));
    }
}
