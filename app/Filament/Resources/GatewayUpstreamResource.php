<?php

namespace App\Filament\Resources;

use App\Filament\Resources\GatewayUpstreamResource\Pages;
use App\Models\GatewayUpstream;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GatewayUpstreamResource extends Resource
{
    protected static ?string $model = GatewayUpstream::class;

    protected static ?string $navigationIcon = 'heroicon-o-server-stack';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'API Servers';

    protected static ?string $modelLabel = 'API server';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255)
                        ->helperText('A friendly name to identify this upstream panel, e.g. "SMM Plus Main".'),

                    Forms\Components\TextInput::make('base_url')
                        ->label('API base URL')
                        ->url()
                        ->required()
                        ->maxLength(2048)
                        ->helperText('e.g. https://smm.plus/api/v2'),

                    Forms\Components\TextInput::make('api_key')
                        ->label('API key')
                        ->password()
                        ->revealable()
                        ->maxLength(255)
                        ->required(fn (?GatewayUpstream $record) => $record === null)
                        ->dehydrated(fn (?string $state) => filled($state))
                        ->helperText(fn (?GatewayUpstream $record) => $record === null
                            ? 'Stored encrypted, never shown again after saving.'
                            : 'Leave blank to keep the current key. Stored encrypted, never shown again after saving.'),

                    Forms\Components\Toggle::make('is_active')
                        ->label('Active')
                        ->default(true)
                        ->helperText('Inactive upstreams and their services are rejected by the gateway.'),
                ])
                ->columns(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_url')
                    ->label('Base URL')
                    ->copyable()
                    ->limit(50),

                Tables\Columns\TextColumn::make('services_count')
                    ->label('Services')
                    ->counts('services'),

                Tables\Columns\IconColumn::make('is_active')
                    ->label('Active')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active')
                    ->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGatewayUpstreams::route('/'),
            'create' => Pages\CreateGatewayUpstream::route('/create'),
            'edit' => Pages\EditGatewayUpstream::route('/{record}/edit'),
        ];
    }
}
