<?php

namespace App\Filament\Resources\GatewayUpstreamResource\Pages;

use App\Filament\Resources\GatewayUpstreamResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGatewayUpstream extends EditRecord
{
    protected static string $resource = GatewayUpstreamResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
