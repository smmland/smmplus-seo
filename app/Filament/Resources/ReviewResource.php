<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ReviewResource\Pages;
use App\Models\Language;
use App\Models\Review;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReviewResource extends Resource
{
    protected static ?string $model = Review::class;

    protected static ?string $navigationIcon = 'heroicon-o-star';

    protected static ?string $navigationGroup = 'Reviews';

    protected static ?string $navigationLabel = 'Reviews';

    protected static ?string $recordTitleAttribute = 'author_name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::REVIEWS)) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::REVIEWS, PanelSection::TIER_EDIT)) ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDelete(Model $record): bool
    {
        return static::canCreate();
    }

    public static function canDeleteAny(): bool
    {
        return static::canCreate();
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\Select::make('lang')
                        ->label('Language')
                        ->options(fn () => Language::query()->where('is_active', true)->orderByRaw('is_default desc')->orderBy('sort_order')->pluck('name', 'code'))
                        ->default(fn () => Language::query()->where('is_default', true)->value('code'))
                        ->searchable()
                        ->required()
                        ->helperText('Which language this review is written in - only shown to visitors browsing the site in this language.'),

                    Forms\Components\TextInput::make('author_name')
                        ->label('Reviewer name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Select::make('rating')
                        ->label('Rating')
                        ->options([
                            5 => '★★★★★ (5)',
                            4 => '★★★★☆ (4)',
                            3 => '★★★☆☆ (3)',
                            2 => '★★☆☆☆ (2)',
                            1 => '★☆☆☆☆ (1)',
                        ])
                        ->default(5)
                        ->required(),

                    Forms\Components\TextInput::make('related_service')
                        ->label('Related service/category')
                        ->maxLength(255)
                        ->helperText('Free text shown alongside the review, e.g. "Instagram Followers". Optional.'),

                    Forms\Components\TextInput::make('country_name')
                        ->label('Country')
                        ->maxLength(255)
                        ->helperText('Display name, e.g. "Iran". Optional.'),

                    Forms\Components\TextInput::make('country_code')
                        ->label('Country code')
                        ->maxLength(2)
                        ->minLength(2)
                        ->helperText('2-letter ISO code (e.g. "IR") - used to render the flag. Optional.')
                        ->formatStateUsing(fn (?string $state) => $state ? strtoupper($state) : $state)
                        ->dehydrateStateUsing(fn (?string $state) => $state ? strtoupper($state) : null),

                    Forms\Components\FileUpload::make('avatar_path')
                        ->label('Avatar (optional)')
                        ->image()
                        ->disk('public')
                        ->directory('reviews/avatars')
                        ->visibility('public')
                        ->columnSpanFull(),

                    Forms\Components\Textarea::make('body')
                        ->label('Review text')
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),

                    Forms\Components\Toggle::make('is_approved')
                        ->label('Approved (visible on the site)')
                        ->default(true)
                        ->helperText('Only approved reviews are ever returned by the public reviews API.'),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('avatar_path')
                    ->label('')
                    ->getStateUsing(fn (Review $record) => $record->avatarUrl())
                    ->circular()
                    ->defaultImageUrl(fn (Review $record) => 'https://ui-avatars.com/api/?name='.urlencode($record->author_name).'&background=random'),

                Tables\Columns\TextColumn::make('lang')
                    ->label('Lang')
                    ->badge()
                    ->formatStateUsing(fn (string $state) => strtoupper($state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('author_name')
                    ->label('Reviewer')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('rating')
                    ->label('Rating')
                    ->formatStateUsing(fn (int $state) => str_repeat('★', $state).str_repeat('☆', 5 - $state))
                    ->sortable(),

                Tables\Columns\TextColumn::make('body')
                    ->label('Review')
                    ->limit(60)
                    ->wrap(),

                Tables\Columns\TextColumn::make('related_service')
                    ->label('Service')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('country_name')
                    ->label('Country')
                    ->formatStateUsing(fn (Review $record) => trim(($record->countryFlag() ?? '').' '.$record->country_name))
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_approved')
                    ->label('Approved')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort(fn ($query) => $query->orderBy('lang')->orderBy('sort_order'))
            ->reorderable('sort_order')
            ->filters([
                // Filter to one language before drag-reordering - display order is set per
                // language, so reordering across an unfiltered, multi-language list would mix
                // their sort_order values together.
                Tables\Filters\SelectFilter::make('lang')
                    ->label('Language')
                    ->options(fn () => Language::query()->where('is_active', true)->orderByRaw('is_default desc')->orderBy('sort_order')->pluck('name', 'code')),

                Tables\Filters\TernaryFilter::make('is_approved')
                    ->label('Approved'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('approve')
                        ->label('Approve')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(fn ($records) => Review::query()->whereIn('id', $records->pluck('id'))->update(['is_approved' => true]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('unapprove')
                        ->label('Unapprove')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(fn ($records) => Review::query()->whereIn('id', $records->pluck('id'))->update(['is_approved' => false]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListReviews::route('/'),
            'create' => Pages\CreateReview::route('/create'),
            'edit' => Pages\EditReview::route('/{record}/edit'),
        ];
    }
}
