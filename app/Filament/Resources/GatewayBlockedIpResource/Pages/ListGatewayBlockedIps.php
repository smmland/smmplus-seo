<?php

namespace App\Filament\Resources\GatewayBlockedIpResource\Pages;

use App\Filament\Resources\GatewayBlockedIpResource;
use App\Services\PanelNotificationService;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGatewayBlockedIps extends ListRecords
{
    protected static string $resource = GatewayBlockedIpResource::class;

    // Visiting this page is itself the "I've seen it" signal for an attack-detected/subsided
    // notification - clears both this nav badge and the bell's own count together.
    public function mount(): void
    {
        parent::mount();

        app(PanelNotificationService::class)->markUrlRead(GatewayBlockedIpResource::getUrl());
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
