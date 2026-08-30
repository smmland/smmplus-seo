<?php

namespace App\Filament\Resources;

use App\Filament\Resources\CatalogServiceResource\Pages;
use App\Models\CatalogService;
use App\Models\Language;
use App\Support\PanelSection;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only mirror of catalog_services (synced from smm.plus's own customer API by
 * CatalogSyncService) - what GET /api/services actually reads its price/min/max from. Exists so
 * an admin can (1) see the real category/name strings before wiring up LandingServiceCategory
 * mappings, since neither smm.plus's API nor the HTML scraper's data was safe to guess at, and
 * (2) set source_label per service (the only manually-curated field here - see
 * LandingServicesController's start_source handling).
 *
 * The Language filter doesn't filter rows out (catalog_services has no lang column - one row per
 * service, language-agnostic) - it just picks which language's Service Translation the
 * Name/Description/Status columns display, via getStateUsing() reading the live filter state off
 * the table's own Livewire component. Every language's translations are eager-loaded once
 * (getEloquentQuery()) so switching the filter never re-queries per row.
 */
class CatalogServiceResource extends Resource
{
    protected static ?string $model = CatalogService::class;

    protected static ?string $navigationIcon = 'heroicon-o-currency-dollar';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Catalog Services';

    protected static ?string $recordTitleAttribute = 'name';

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with('translations');
    }

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

    private static function defaultLang(): string
    {
        return Language::query()->where('is_default', true)->value('code') ?? 'en';
    }

    // $livewire is the ListRecords page itself (implements Filament's HasTable), which exposes
    // the live filter values via getTableFilterState() - null before the filter form has been
    // interacted with (first render), hence the fallback to the site's default language.
    private static function selectedLang($livewire): string
    {
        return $livewire?->getTableFilterState('lang')['value'] ?? static::defaultLang();
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
                    ->label('Name')
                    ->getStateUsing(function (CatalogService $record, $livewire) {
                        $translation = $record->translations->firstWhere('lang', static::selectedLang($livewire));

                        return $translation?->title ?: $record->name;
                    })
                    ->searchable()
                    ->sortable()
                    ->wrap(),

                Tables\Columns\TextColumn::make('translation_status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(function (CatalogService $record, $livewire) {
                        $lang = static::selectedLang($livewire);

                        if ($lang === static::defaultLang()) {
                            return 'Source';
                        }

                        $translation = $record->translations->firstWhere('lang', $lang);

                        if (! $translation) {
                            return 'Not queued';
                        }

                        return $translation->looksTranslated() ? 'Translated' : 'Pending';
                    })
                    ->color(fn (string $state) => match ($state) {
                        'Translated' => 'success',
                        'Pending' => 'warning',
                        'Not queued' => 'danger',
                        default => 'gray',
                    }),

                Tables\Columns\TextColumn::make('description')
                    ->label('Description')
                    ->getStateUsing(function (CatalogService $record, $livewire) {
                        return $record->translations->firstWhere('lang', static::selectedLang($livewire))?->description_text;
                    })
                    ->placeholder('—')
                    ->wrap()
                    ->limit(150)
                    ->toggleable(isToggledHiddenByDefault: true),

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
                Tables\Filters\SelectFilter::make('lang')
                    ->label('Language')
                    ->options(fn () => Language::query()->where('is_active', true)->orderByRaw('is_default desc')->orderBy('sort_order')->pluck('name', 'code'))
                    ->default(fn () => static::defaultLang())
                    // Display-only - see the class docblock. No column on catalog_services to
                    // filter rows by, so this deliberately leaves the query untouched.
                    ->query(fn (Builder $query) => $query),

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
