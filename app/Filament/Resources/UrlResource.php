<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UrlResource\Pages;
use App\Models\Language;
use App\Models\Url;
use App\Services\BlogTranslationDetectionService;
use App\Services\SitemapGeneratorService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class UrlResource extends Resource
{
    protected static ?string $model = Url::class;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?string $navigationGroup = 'SEO';

    protected static ?string $navigationLabel = 'URLs';

    protected static ?string $recordTitleAttribute = 'source_url';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('source_url')
                        ->label('URL')
                        ->url()
                        ->required()
                        ->maxLength(2048)
                        ->unique(ignoreRecord: true)
                        // Only editable on create - once synced/classified, editing the URL itself
                        // would desync path/slug/group_key, which only the classifier should own.
                        ->disabled(fn (?Url $record) => $record !== null)
                        ->dehydrated(),

                    Forms\Components\Select::make('pattern_type')
                        ->label('Category')
                        ->options(array_combine(Url::PATTERN_TYPES, Url::PATTERN_TYPES))
                        ->required()
                        ->helperText('Changing this on an existing URL marks it as manually-managed, so future syncs will no longer auto-reclassify it.'),

                    Forms\Components\TextInput::make('lang')
                        ->label('Language code')
                        ->maxLength(8)
                        ->visible(fn (?Url $record) => $record === null)
                        ->helperText('Leave blank to auto-detect from the URL path (e.g. /ar/... -> ar).'),

                    Forms\Components\Toggle::make('is_hidden')
                        ->label('Hidden from sitemap')
                        ->visible(fn (?Url $record) => $record !== null),

                    Forms\Components\TextInput::make('priority')
                        ->numeric()
                        ->minValue(0)
                        ->maxValue(1)
                        ->step(0.1)
                        ->visible(fn (?Url $record) => $record !== null),

                    Forms\Components\TextInput::make('changefreq')
                        ->maxLength(20)
                        ->visible(fn (?Url $record) => $record !== null),
                ])
                ->columns(1),

            Forms\Components\Section::make('Extracted title & SEO meta')
                ->description('Filled in by "Extract content" on the Blog Translation queue page. Editable here for review/translation.')
                ->visible(fn (?Url $record) => $record !== null && $record->content_extracted_at !== null)
                ->schema([
                    Forms\Components\TextInput::make('article_title')
                        ->label('Article title (on-page H1)')
                        ->maxLength(255),

                    Forms\Components\TextInput::make('seo_title')
                        ->label('Page <title>')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('meta_description')
                        ->rows(2),

                    Forms\Components\TextInput::make('meta_keywords')
                        ->maxLength(500),

                    Forms\Components\TextInput::make('og_title')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('og_description')
                        ->rows(2),

                    Forms\Components\TextInput::make('twitter_title')
                        ->maxLength(255),

                    Forms\Components\Textarea::make('twitter_description')
                        ->rows(2),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        $defaultLang = Language::query()->where('is_default', true)->value('code') ?? 'en';

        return $table
            ->columns([
                Tables\Columns\TextColumn::make('#')
                    ->rowIndex(),

                Tables\Columns\TextColumn::make('source_url')
                    ->label('URL')
                    ->searchable()
                    ->sortable()
                    ->limit(60)
                    ->copyable()
                    ->url(fn (Url $record) => $record->source_url, shouldOpenInNewTab: true),

                Tables\Columns\TextColumn::make('pattern_type')
                    ->label('Category')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('lang')
                    ->label('Lang')
                    ->badge()
                    ->color('gray')
                    ->sortable(),

                Tables\Columns\ToggleColumn::make('is_hidden')
                    ->label('Hidden')
                    ->afterStateUpdated(fn () => app(SitemapGeneratorService::class)->generate()),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('missed_syncs')
                    ->label('Missed syncs')
                    ->tooltip('How many syncs in a row this URL has been absent from the source sitemap - deactivated once this reaches 3. Above 0 means it\'s at risk even while still active.')
                    ->badge()
                    ->color(fn (?int $state) => $state > 0 ? 'warning' : 'gray')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_manual')
                    ->label('Manual')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('auto_hidden_for_translation')
                    ->label('Hidden (untranslated)')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_translated')
                    ->label('Translated')
                    ->boolean()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('translation_title')
                    ->label('Fetched title')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('article_title')
                    ->label('Article title')
                    ->limit(50)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('translation_check_note')
                    ->label('Last check note')
                    ->limit(60)
                    ->tooltip(fn (Url $record) => $record->translation_check_note)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('translation_checked_at')
                    ->label('Last checked')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('group_key')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('source_lastmod')
                    ->label('Last modified')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('source_url')
            ->filters([
                Tables\Filters\SelectFilter::make('pattern_type')
                    ->label('Category')
                    ->options(array_combine(Url::PATTERN_TYPES, Url::PATTERN_TYPES)),

                Tables\Filters\SelectFilter::make('lang')
                    ->label('Language')
                    ->multiple()
                    ->options(fn () => Url::query()->distinct()->orderBy('lang')->pluck('lang', 'lang')->all()),

                Tables\Filters\TernaryFilter::make('is_hidden')
                    ->label('Visibility')
                    ->trueLabel('Hidden only')
                    ->falseLabel('Visible only'),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active')
                    ->trueLabel('Active only')
                    ->falseLabel('Inactive only (removed from source)'),

                Tables\Filters\TernaryFilter::make('auto_hidden_for_translation')
                    ->label('Hidden for translation')
                    ->trueLabel('Auto-hidden (untranslated) only')
                    ->falseLabel('Not auto-hidden'),
            ])
            ->actions([
                Tables\Actions\Action::make('recheckTranslation')
                    ->label('Recheck')
                    ->icon('heroicon-o-arrow-path')
                    ->color('gray')
                    ->visible(fn (Url $record) => $record->pattern_type === 'BLOG' && $record->lang !== $defaultLang)
                    ->action(function (Url $record) {
                        $result = app(BlogTranslationDetectionService::class)->refreshOne($record);
                        $record->refresh();

                        if ($result['errors'] > 0) {
                            Notification::make()
                                ->title('Could not check this URL')
                                ->body($record->translation_check_note ?? 'No default-language URL found for this topic.')
                                ->danger()
                                ->send();

                            return;
                        }

                        $notification = Notification::make()
                            ->title($record->is_translated ? 'Translated' : 'Still untranslated')
                            ->body($record->translation_check_note);

                        $record->is_translated ? $notification->success() : $notification->warning();

                        $notification->send();
                    }),

                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    // Mirrors the API rule: only manually-added or already-inactive rows can be deleted;
                    // anything still coming from the source sitemap should be hidden instead.
                    ->visible(fn (Url $record) => $record->is_manual || ! $record->is_active)
                    ->after(fn () => app(SitemapGeneratorService::class)->generate()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('hide')
                        ->label('Hide from sitemap')
                        ->icon('heroicon-o-eye-slash')
                        ->action(function ($records) {
                            Url::query()->whereIn('id', $records->pluck('id'))->update(['is_hidden' => true]);
                            app(SitemapGeneratorService::class)->generate();
                        })
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('show')
                        ->label('Show in sitemap')
                        ->icon('heroicon-o-eye')
                        ->action(function ($records) {
                            Url::query()->whereIn('id', $records->pluck('id'))->update(['is_hidden' => false]);
                            app(SitemapGeneratorService::class)->generate();
                        })
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUrls::route('/'),
            'create' => Pages\CreateUrl::route('/create'),
            'edit' => Pages\EditUrl::route('/{record}/edit'),
        ];
    }
}
