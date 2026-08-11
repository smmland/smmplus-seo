<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayBlockedIpResource\Pages;
use App\Models\GatewayBlockedIp;
use App\Services\CpanelIpBlockerService;
use App\Services\PanelNotificationService;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class GatewayBlockedIpResource extends Resource
{
    protected static ?string $model = GatewayBlockedIp::class;

    protected static ?string $navigationIcon = 'heroicon-o-no-symbol';

    protected static ?string $navigationGroup = 'Security';

    protected static ?string $navigationLabel = 'Blocked IPs';

    protected static ?string $modelLabel = 'blocked IP';

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyAccess(PanelSection::viewOrEditKeys(PanelSection::SECURITY)) ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(PanelNotificationService::class)->unreadCountForUrl(static::getUrl());

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasAccess(PanelSection::key(PanelSection::SECURITY, PanelSection::TIER_EDIT)) ?? false;
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

                Tables\Columns\TextColumn::make('blocked_until')
                    ->label('Auto-expires')
                    ->dateTime()
                    ->placeholder('Never (manual)')
                    ->sortable(),

                Tables\Columns\TextColumn::make('offense_count')
                    ->label('Offenses')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\IconColumn::make('cpanel_synced')
                    ->label('cPanel')
                    ->getStateUsing(fn (GatewayBlockedIp $record) => $record->cpanel_synced_at !== null)
                    ->boolean()
                    ->tooltip(fn (GatewayBlockedIp $record) => $record->cpanel_sync_error
                        ?: ($record->cpanel_synced_at ? 'Synced to cPanel IP Blocker '.$record->cpanel_synced_at->diffForHumans() : 'cPanel IP Blocker not configured or not yet attempted'))
                    ->toggleable(isToggledHiddenByDefault: true),

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
                    ->visible(fn (): bool => static::canCreate())
                    ->action(function (GatewayBlockedIp $record) {
                        if ($record->is_active) {
                            // Also lifts it at the web-server level, not just locally - a manual
                            // unblock here that skipped this would leave the IP rejected by
                            // cPanel forever, since AutoBlockAbusiveIpsCommand's expiry sweep
                            // only looks at records still marked is_active.
                            app(CpanelIpBlockerService::class)->unblock($record);
                            $record->update(['is_active' => false]);

                            return;
                        }

                        // Re-blocking manually from here is a permanent block, not a timed
                        // auto-block, so clear any leftover expiry from a past auto-block.
                        $record->update(['is_active' => true, 'blocked_until' => null]);
                    }),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('block')
                        ->label('Block')
                        ->icon('heroicon-o-lock-closed')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(fn ($records) => GatewayBlockedIp::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => true, 'blocked_until' => null]))
                        ->deselectRecordsAfterCompletion(),

                    Tables\Actions\BulkAction::make('unblock')
                        ->label('Unblock')
                        ->icon('heroicon-o-lock-open')
                        ->visible(fn (): bool => static::canCreate())
                        ->action(function ($records) {
                            // Same reasoning as the single-row toggle action: lift the cPanel
                            // side too, per record, before flipping is_active - otherwise a
                            // gradual restore (e.g. unblocking a batch of the Tor bulk-block a
                            // week later) would look successful here but leave every one of
                            // them still rejected by the web server.
                            $records->each(fn (GatewayBlockedIp $record) => app(CpanelIpBlockerService::class)->unblock($record));

                            GatewayBlockedIp::query()->whereIn('id', $records->pluck('id'))->update(['is_active' => false]);
                        })
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
