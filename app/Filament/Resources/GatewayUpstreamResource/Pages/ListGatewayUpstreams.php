<?php

namespace App\Filament\Resources\GatewayUpstreamResource\Pages;

use App\Filament\Resources\GatewayUpstreamResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListGatewayUpstreams extends ListRecords
{
    protected static string $resource = GatewayUpstreamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
