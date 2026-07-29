<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SyncRunResource\Pages;
use App\Models\SyncRun;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SyncRunResource extends Resource
{
    protected static ?string $model = SyncRun::class;

    protected static ?string $navigationIcon = 'heroicon-o-arrow-path';

    protected static ?string $navigationGroup = 'Sitemap';

    protected static ?string $navigationLabel = 'Sync History';

    // Read-only log of sync runs - nothing to create or edit by hand.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        SyncRun::SUCCESS => 'success',
                        SyncRun::FAILED => 'danger',
                        default => 'warning',
                    }),
                Tables\Columns\TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('finished_at')
                    ->dateTime()
                    ->sortable(),
                Tables\Columns\TextColumn::make('total_fetched')
                    ->label('Fetched')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('added')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('updated')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('removed')
                    ->numeric()
                    ->sortable(),
                Tables\Columns\TextColumn::make('error_message')
                    ->limit(80)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('started_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        SyncRun::RUNNING => 'Running',
                        SyncRun::SUCCESS => 'Success',
                        SyncRun::FAILED => 'Failed',
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSyncRuns::route('/'),
        ];
    }
}
