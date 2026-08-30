<?php

namespace App\Filament\Resources\CatalogServiceResource\Pages;

use App\Filament\Resources\CatalogServiceResource;
use App\Services\CatalogSyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCatalogServices extends ListRecords
{
    protected static string $resource = CatalogServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('syncNow')
                ->label('Sync now')
                ->icon('heroicon-o-arrow-path')
                ->requiresConfirmation()
                ->action(function (CatalogSyncService $sync) {
                    $result = $sync->sync();

                    if (! $result['ok']) {
                        Notification::make()
                            ->title('Could not sync the catalog')
                            ->body($result['error'])
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Catalog synced')
                        ->body("{$result['total']} service(s): {$result['added']} new, {$result['changed']} changed, {$result['unavailable']} no longer available, {$result['seededTranslations']} newly seen by the translation pipeline (auto-translated into every active language on the next hourly translation run).")
                        ->success()
                        ->send();
                }),
        ];
    }
}
