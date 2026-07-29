<?php

namespace App\Filament\Resources\SyncRunResource\Pages;

use App\Filament\Resources\SyncRunResource;
use App\Services\SitemapGeneratorService;
use App\Services\SyncService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Throwable;

class ListSyncRuns extends ListRecords
{
    protected static string $resource = SyncRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('runSync')
                ->label('Run sync now')
                ->icon('heroicon-o-play')
                ->requiresConfirmation()
                ->action(function (SyncService $sync, SitemapGeneratorService $generator) {
                    try {
                        $result = $sync->runSync();
                        $generator->generate();

                        Notification::make()
                            ->title('Sync finished')
                            ->body("Fetched {$result['total_fetched']}, added {$result['added']}, updated {$result['updated']}, removed {$result['removed']}.")
                            ->success()
                            ->send();
                    } catch (Throwable $e) {
                        Notification::make()
                            ->title('Sync failed')
                            ->body($e->getMessage())
                            ->danger()
                            ->send();
                    }
                }),
        ];
    }
}
