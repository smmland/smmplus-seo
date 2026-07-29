<?php

namespace App\Filament\Resources\GatewayServiceResource\Pages;

use App\Filament\Resources\GatewayServiceResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditGatewayService extends EditRecord
{
    protected static string $resource = GatewayServiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }
}
