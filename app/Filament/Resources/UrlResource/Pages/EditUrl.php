<?php

namespace App\Filament\Resources\UrlResource\Pages;

use App\Filament\Resources\UrlResource;
use App\Services\SitemapGeneratorService;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditUrl extends EditRecord
{
    protected static string $resource = UrlResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->visible(fn () => $this->record->is_manual || ! $this->record->is_active),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        if ($data['pattern_type'] !== $this->record->pattern_type) {
            $data['is_manual'] = true;
        }

        return $data;
    }

    protected function afterSave(): void
    {
        app(SitemapGeneratorService::class)->generate();
    }
}
