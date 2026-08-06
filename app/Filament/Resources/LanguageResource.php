<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LanguageResource\Pages;
use App\Models\Language;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class LanguageResource extends Resource
{
    protected static ?string $model = Language::class;

    protected static ?string $navigationIcon = 'heroicon-o-language';

    protected static ?string $navigationGroup = 'Translation';

    protected static ?string $navigationLabel = 'Languages';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::TRANSLATION)) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::TRANSLATION, PanelSection::TIER_EDIT)) ?? false;
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
                    Forms\Components\TextInput::make('code')
                        ->label('Code / path prefix')
                        ->required()
                        ->maxLength(8)
                        ->unique(ignoreRecord: true)
                        ->helperText('e.g. "fa" for /fa/blog/... URLs.'),

                    Forms\Components\TextInput::make('name')
                        ->label('Native name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\Toggle::make('is_default')
                        ->label('Default language')
                        ->helperText('The reference language every other language\'s blog title is compared against. Only one language should be default.'),

                    Forms\Components\Toggle::make('is_active')
                        ->default(true)
                        ->helperText('Inactive languages are ignored by the translation queue and detector.'),

                    Forms\Components\TextInput::make('sort_order')
                        ->numeric()
                        ->default(0),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('name')
                    ->searchable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('sort_order')
                    ->label('Order')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLanguages::route('/'),
            'create' => Pages\CreateLanguage::route('/create'),
            'edit' => Pages\EditLanguage::route('/{record}/edit'),
        ];
    }
}
