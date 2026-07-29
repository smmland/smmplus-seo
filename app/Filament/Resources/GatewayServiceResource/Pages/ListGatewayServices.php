<?php

namespace App\Filament\Resources\GatewayServiceResource\Pages;

use App\Filament\Resources\GatewayServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGatewayServices extends ListRecords
{
    protected static string $resource = GatewayServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
