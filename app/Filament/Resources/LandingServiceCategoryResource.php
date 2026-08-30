<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LandingServiceCategoryResource\Pages;
use App\Models\LandingServiceCategory;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-managed mapping from a landing page's ?category= slug to a substring match against the
 * real, synced catalog_services.category/name text (CatalogServiceResource is where the admin
 * goes to see what that real text actually is) - built this way instead of any hardcoded guess at
 * smm.plus's real category labels or GEO/non-GEO wording.
 */
class LandingServiceCategoryResource extends Resource
{
    protected static ?string $model = LandingServiceCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-tag';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'Landing Categories';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SEO)) ?? false;
    }

    public static function canCreate(): bool
    {
        return static::canEdit();
    }

    public static function canEdit(?Model $record = null): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::SEO, PanelSection::TIER_EDIT)) ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return static::canEdit();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('slug')
                ->required()
                ->maxLength(255)
                ->unique(ignoreRecord: true)
                ->helperText('Used as GET /api/services?category=<slug> - e.g. "premium_botstart".'),

            Forms\Components\TextInput::make('label')
                ->required()
                ->maxLength(255)
                ->helperText('Admin-facing name only - never shown on the public API.'),

            Forms\Components\Select::make('match_field')
                ->options([
                    LandingServiceCategory::MATCH_FIELD_CATEGORY => 'Category',
                    LandingServiceCategory::MATCH_FIELD_NAME => 'Service name',
                ])
                ->default(LandingServiceCategory::MATCH_FIELD_CATEGORY)
                ->required(),

            Forms\Components\TextInput::make('match_text')
                ->required()
                ->maxLength(255)
                ->helperText('Case-insensitive substring checked against every synced service\'s category/name (see Catalog Services for the real values).'),

            Forms\Components\TextInput::make('geo_keyword')
                ->maxLength(255)
                ->helperText('Optional. Substring that marks a matched service as GEO/country-targeted (checked against the same field above) - e.g. "GEO". Leave blank if this category has no GEO/non-GEO split.'),

            Forms\Components\Toggle::make('is_active')
                ->default(true)
                ->helperText('Inactive categories return 404 from the public API instead of being matched.'),

            Forms\Components\TextInput::make('sort_order')
                ->numeric()
                ->default(0),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('label')
                    ->searchable(),

                Tables\Columns\TextColumn::make('match_field')
                    ->badge(),

                Tables\Columns\TextColumn::make('match_text')
                    ->label('Matches'),

                Tables\Columns\TextColumn::make('geo_keyword')
                    ->label('GEO keyword')
                    ->placeholder('— (no GEO split)'),

                Tables\Columns\IconColumn::make('is_active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading('No landing categories yet')
            ->emptyStateDescription('Create one to expose a slice of the synced catalog at GET /api/services?category=<slug>.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLandingServiceCategories::route('/'),
            'create' => Pages\CreateLandingServiceCategory::route('/create'),
            'edit' => Pages\EditLandingServiceCategory::route('/{record}/edit'),
        ];
    }
}
