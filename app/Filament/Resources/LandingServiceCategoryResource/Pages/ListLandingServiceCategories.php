<?php

namespace App\Filament\Resources\LandingServiceCategoryResource\Pages;

use App\Filament\Resources\LandingServiceCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListLandingServiceCategories extends ListRecords
{
    protected static string $resource = LandingServiceCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}
