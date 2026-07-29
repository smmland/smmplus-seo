<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayRequestLogResource\Pages;
use App\Models\GatewayBlockedIp;
use App\Models\GatewayRequestLog;
use App\Models\GatewayService;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GatewayRequestLogResource extends Resource
{
    protected static ?string $model = GatewayRequestLog::class;

    protected static ?string $navigationIcon = 'heroicon-o-list-bullet';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Request Log';

    protected static ?string $modelLabel = 'request';

    // Append-only audit log - nothing to create or edit by hand.
    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label('Time')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('ip')
                    ->searchable()
                    ->copyable(),

                Tables\Columns\TextColumn::make('service_slug')
                    ->label('Service')
                    ->searchable()
                    ->badge()
                    ->color('gray'),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state) => match ($state) {
                        GatewayRequestLog::STATUS_SUCCESS => 'success',
                        GatewayRequestLog::STATUS_BLOCKED_IP, GatewayRequestLog::STATUS_INVALID_ORIGIN => 'danger',
                        default => 'warning',
                    }),

                Tables\Columns\TextColumn::make('target')
                    ->limit(40)
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('quantity_requested')
                    ->label('Requested')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('quantity_allowed')
                    ->label('Allowed')
                    ->alignEnd(),

                Tables\Columns\TextColumn::make('origin')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('service_slug')
                    ->label('Service')
                    ->options(fn () => GatewayService::query()->orderBy('slug')->pluck('slug', 'slug')),

                Tables\Filters\SelectFilter::make('status')
                    ->options([
                        GatewayRequestLog::STATUS_SUCCESS => 'Success',
                        GatewayRequestLog::STATUS_BLOCKED_IP => 'Blocked IP',
                        GatewayRequestLog::STATUS_INVALID_ORIGIN => 'Invalid origin',
                        GatewayRequestLog::STATUS_MISSING_SERVICE => 'Missing service',
                        GatewayRequestLog::STATUS_INVALID_SERVICE => 'Invalid service',
                        GatewayRequestLog::STATUS_MISSING_LINK => 'Missing link',
                        GatewayRequestLog::STATUS_GLOBAL_IP_LIMIT => 'Global IP limit',
                        GatewayRequestLog::STATUS_GLOBAL_TARGET_LIMIT => 'Global target limit',
                        GatewayRequestLog::STATUS_IP_LIMIT => 'Service IP limit',
                        GatewayRequestLog::STATUS_TARGET_LIMIT => 'Service target limit',
                        GatewayRequestLog::STATUS_BELOW_MIN_QUANTITY => 'Below min quantity',
                        GatewayRequestLog::STATUS_UPSTREAM_ERROR => 'Upstream error',
                    ]),

                Tables\Filters\Filter::make('ip')
                    ->form([
                        Forms\Components\TextInput::make('ip')->label('IP address'),
                    ])
                    ->query(fn ($query, array $data) => $query->when(
                        $data['ip'] ?? null,
                        fn ($query, $ip) => $query->where('ip', $ip),
                    )),

                Tables\Filters\Filter::make('created_at')
                    ->form([
                        Forms\Components\DatePicker::make('from'),
                        Forms\Components\DatePicker::make('until'),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\Action::make('blockIp')
                    ->label('Block IP')
                    ->icon('heroicon-o-no-symbol')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (GatewayRequestLog $record) => ! GatewayBlockedIp::isBlocked($record->ip))
                    ->action(function (GatewayRequestLog $record) {
                        GatewayBlockedIp::query()->updateOrCreate(
                            ['ip' => $record->ip],
                            ['is_active' => true, 'blocked_until' => null, 'note' => 'Blocked from request log'],
                        );

                        Notification::make()
                            ->title("Blocked {$record->ip}")
                            ->success()
                            ->send();
                    }),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayRequestLogs::route('/'),
        ];
    }
}
