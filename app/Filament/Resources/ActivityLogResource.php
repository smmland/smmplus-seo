<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityLogResource\Pages;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PanelSection;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The audit trail viewer - who did what, when. Read-only (no create/edit; delete stays available
 * to super admins only, for pruning old entries by hand since there's no automatic retention
 * policy). See ActivityLogService for how entries get here in the first place, and
 * App\Models\Concerns\LogsActivity's docblock for exactly what is and isn't covered.
 */
class ActivityLogResource extends Resource
{
    protected static ?string $model = ActivityLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Activity Log';

    // Same reasoning as UserResource - reached from the header's user menu, not the sidebar.
    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $recordTitleAttribute = 'action';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    // Nothing here is ever created or edited by hand - only recorded automatically.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('user_name')
                    ->label('Who')
                    ->placeholder('(system)')
                    ->searchable(),

                Tables\Columns\TextColumn::make('action')
                    ->badge()
                    ->searchable(),

                Tables\Columns\TextColumn::make('subject_label')
                    ->label('Subject')
                    ->limit(50)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('section')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (?string $state): string => $state ? (PanelSection::SECTION_LABELS[$state] ?? $state) : '—'),

                Tables\Columns\TextColumn::make('ip_address')
                    ->label('IP')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('user_id')
                    ->label('User')
                    ->options(fn () => User::query()->pluck('name', 'id')),

                Tables\Filters\SelectFilter::make('section')
                    ->options(PanelSection::SECTION_LABELS),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        \Filament\Forms\Components\DatePicker::make('from'),
                        \Filament\Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(function ($query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function infolist(\Filament\Infolists\Infolist $infolist): \Filament\Infolists\Infolist
    {
        return $infolist->schema([
            TextEntry::make('created_at')->label('When')->dateTime(),
            TextEntry::make('user_name')->label('Who')->placeholder('(system)'),
            TextEntry::make('ip_address')->label('IP address')->placeholder('—'),
            TextEntry::make('action')->badge(),
            TextEntry::make('section')
                ->formatStateUsing(fn (?string $state): string => $state ? (PanelSection::SECTION_LABELS[$state] ?? $state) : '—'),
            TextEntry::make('subject_type')->label('Subject type')->placeholder('—'),
            TextEntry::make('subject_label')->label('Subject')->placeholder('—'),
            TextEntry::make('changes')
                ->label('Changes')
                ->formatStateUsing(fn (?array $state): string => json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                ->visible(fn (ActivityLog $record): bool => filled($record->changes))
                ->columnSpanFull(),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivityLogs::route('/'),
            'view' => Pages\ViewActivityLog::route('/{record}'),
        ];
    }
}
