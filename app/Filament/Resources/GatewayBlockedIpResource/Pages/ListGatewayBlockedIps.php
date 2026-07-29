<?php

namespace App\Filament\Resources\GatewayBlockedIpResource\Pages;

use App\Filament\Resources\GatewayBlockedIpResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGatewayBlockedIps extends ListRecords
{
    protected static string $resource = GatewayBlockedIpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
