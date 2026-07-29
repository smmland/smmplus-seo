<?php

namespace App\Filament\Resources\UrlResource\Pages;

use App\Filament\Resources\UrlResource;
use App\Services\SitemapGeneratorService;
use App\Services\UrlClassifierService;
use Filament\Resources\Pages\CreateRecord;

class CreateUrl extends CreateRecord
{
    protected static string $resource = UrlResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $classified = app(UrlClassifierService::class)->classify($data['source_url']);

        return [
            ...$data,
            'path' => $classified['path'],
            'lang' => $data['lang'] ?: $classified['lang'],
            'slug' => $classified['slug'],
            'group_key' => $classified['group_key'],
            'is_manual' => true,
            'is_active' => true,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    protected function afterCreate(): void
    {
        app(SitemapGeneratorService::class)->generate();
    }
}
