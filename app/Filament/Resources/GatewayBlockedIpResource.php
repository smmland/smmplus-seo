<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayBlockedIpResource\Pages;
use App\Models\GatewayBlockedIp;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GatewayBlockedIpResource extends Resource
{
    protected static ?string $model = GatewayBlockedIp::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Blocked IPs';

    protected static ?string $modelLabel = 'blocked IP';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('ip')
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('note')
                        ->maxLength(255)
                        ->helperText('Optional reason, e.g. "excessive requests on 2026-07-29".'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Blocked')
                        ->default(true)
                        ->helperText('Turn off to unblock without deleting the record.'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('ip')
                    ->searchable()
                    ->sortable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('note')
                    ->limit(60)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Blocked')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Added')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Blocked')
                    ->trueLabel('Currently blocked')
                    ->falseLabel('Unblocked'),
            ])
            ->actions([
                Tables\Actions\Action::make('toggle')
                    ->label(fn (GatewayBlockedIp $record) => $record->is_active ? 'Unblock' : 'Block')
                    ->icon(fn (GatewayBlockedIp $record) => $record->is_active ? 'heroicon-o-lock-open' : 'heroicon-o-lock-closed')
                    ->color(fn (GatewayBlockedIp $record) => $record->is_active ? 'success' : 'danger')
                    ->action(fn (GatewayBlockedIp $record) => $record->update(['is_active' => ! $record->is_active])),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('block')
                        ->label('Block')
                        ->icon('heroicon-o-lock-closed')
                        ->action(fn ($records) => GatewayBlockedIp::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => true]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('unblock')
                        ->label('Unblock')
                        ->icon('heroicon-o-lock-open')
                        ->action(fn ($records) => GatewayBlockedIp::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => false]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayBlockedIps::route('/'),
            'create' => Pages\CreateGatewayBlockedIp::route('/create'),
            'edit' => Pages\EditGatewayBlockedIp::route('/{record}/edit'),
        ];
    }
}
