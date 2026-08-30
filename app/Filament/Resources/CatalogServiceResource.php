<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogServiceResource\Pages;
use App\Models\CatalogService;
use App\Support\PanelSection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Read-only mirror of catalog_services (synced from smm.plus's own customer API by
 * CatalogSyncService) - what GET /api/services actually reads its price/min/max from. Exists so
 * an admin can (1) see the real category/name strings before wiring up LandingServiceCategory
 * mappings, since neither smm.plus's API nor the HTML scraper's data was safe to guess at, and
 * (2) set source_label per service (the only manually-curated field here - see
 * LandingServicesController's start_source handling).
 */
class CatalogServiceResource extends Resource
{
    protected static ?string $model = CatalogService::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Catalog Services';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SEO)) ?? false;
    }

    // Synced automatically - nothing here to hand-create.
    public static function canCreate(): bool
    {
        return false;
    }

    private static function canEditLabel(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::SEO, PanelSection::TIER_EDIT)) ?? false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('service_id')
                    ->label('ID')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('category')
                    ->searchable()
                    ->sortable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('type')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('rate')
                    ->label('Rate / 1000')
                    ->sortable(),

                Tables\Columns\TextColumn::make('min')
                    ->sortable(),

                Tables\Columns\TextColumn::make('max')
                    ->sortable(),

                Tables\Columns\IconColumn::make('refill')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('cancel')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('available')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextInputColumn::make('source_label')
                    ->label('Source')
                    ->placeholder('e.g. "Telegram Search" - leave blank if unknown')
                    ->disabled(fn () => ! static::canEditLabel()),

                Tables\Columns\TextColumn::make('synced_at')
                    ->label('Last synced')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\TernaryFilter::make('available'),
                Tables\Filters\SelectFilter::make('category')
                    ->options(fn () => CatalogService::query()->whereNotNull('category')->distinct()->orderBy('category')->pluck('category', 'category')),
            ])
            ->emptyStateHeading('No services synced yet')
            ->emptyStateDescription('Pick an API server in SEO Settings, then run a sync.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogServices::route('/'),
        ];
    }
}
