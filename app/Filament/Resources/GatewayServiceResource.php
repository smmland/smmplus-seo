<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayServiceResource\Pages;
use App\Models\GatewayService;
use App\Models\GatewayUpstream;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GatewayServiceResource extends Resource
{
    protected static ?string $model = GatewayService::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Services';

    protected static ?string $recordTitleAttribute = 'label';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::FREE_SERVICE)) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::FREE_SERVICE, PanelSection::TIER_EDIT)) ?? false;
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
                    Forms\Components\Select::make('gateway_upstream_id')
                        ->label('API server')
                        ->options(fn () => GatewayUpstream::query()->pluck('name', 'id'))
                        ->required()
                        ->searchable(),

                    Forms\Components\TextInput::make('slug')
                        ->label('Public service key')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->helperText('The identifier the frontend sends in the "service" field, e.g. "telegram-postviews".'),

                    Forms\Components\TextInput::make('label')
                        ->required()
                        ->maxLength(255)
                        ->helperText('A friendly name shown only in this admin panel.'),

                    Forms\Components\TextInput::make('upstream_service_id')
                        ->label('Upstream service ID')
                        ->numeric()
                        ->required()
                        ->helperText('The numeric service ID on the upstream API server.'),

                    Forms\Components\TextInput::make('min_quantity')
                        ->label('Min quantity')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\TextInput::make('max_quantity')
                        ->label('Max quantity')
                        ->numeric()
                        ->required()
                        ->minValue(1),

                    Forms\Components\TextInput::make('limit_seconds')
                        ->label('Rate limit window (seconds)')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->helperText('How long the IP/target quantity caps below apply for.'),

                    Forms\Components\TextInput::make('ip_limit')
                        ->label('Per-IP quantity limit')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->helperText('Max total quantity one IP can order within the rate limit window.'),

                    Forms\Components\TextInput::make('target_limit')
                        ->label('Per-target quantity limit')
                        ->numeric()
                        ->required()
                        ->minValue(1)
                        ->helperText('Max total quantity one link/username can receive within the rate limit window.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true),
                ])
                ->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('slug')
                    ->label('Service key')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('label')
                    ->searchable(),

                Tables\Columns\TextColumn::make('upstream.name')
                    ->label('API server')
                    ->sortable(),

                Tables\Columns\TextColumn::make('upstream_service_id')
                    ->label('Upstream ID'),

                Tables\Columns\TextColumn::make('min_quantity')
                    ->label('Min')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('max_quantity')
                    ->label('Max')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('ip_limit')
                    ->label('IP limit')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('target_limit')
                    ->label('Target limit')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('limit_seconds')
                    ->label('Window (s)')
                    ->alignEnd()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),
            ])
            ->defaultSort('slug')
            ->filters([
                Tables\Filters\SelectFilter::make('gateway_upstream_id')
                    ->label('API server')
                    ->options(fn () => GatewayUpstream::query()->pluck('name', 'id')),

                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('activate')
                        ->label('Activate')
                        ->icon('heroicon-o-check-circle')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(fn ($records) => GatewayService::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('deactivate')
                        ->label('Deactivate')
                        ->icon('heroicon-o-x-circle')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(fn ($records) => GatewayService::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayServices::route('/'),
            'create' => Pages\CreateGatewayService::route('/create'),
            'edit' => Pages\EditGatewayService::route('/{record}/edit'),
        ];
    }
}
