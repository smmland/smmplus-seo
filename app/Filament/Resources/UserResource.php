<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\User;
use App\Support\PanelSection;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

/**
 * Who can log into this panel, and which of the six grantable PanelSection areas each one can
 * see - the only thing that decides that is this page, so it's deliberately restricted to
 * super admins only (canViewAny() below), same as PanelUpdate: handing out user management is
 * equivalent to handing out full access regardless of what a user's own permissions say.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Administration';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $recordTitleAttribute = 'name';

    public static function canViewAny(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->required()
                        ->maxLength(255),

                    Forms\Components\TextInput::make('email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),

                    Forms\Components\TextInput::make('password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (string $context): bool => $context === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                        ->helperText(fn (string $context): ?string => $context === 'edit'
                            ? 'Leave blank to keep the current password.'
                            : null),

                    Forms\Components\Toggle::make('is_super_admin')
                        ->label('Super admin (full access)')
                        ->live()
                        ->disabled(fn (?User $record): bool => $record?->id === auth()->id())
                        ->helperText(fn (?User $record): string => $record?->id === auth()->id()
                            ? "You can't change your own super admin status."
                            : 'Bypasses every section below - only for trusted admins, since it also grants installing panel updates and managing other users\' access.'),
                ])
                ->columns(2),

            Forms\Components\Section::make('Section access')
                ->description('What this user can see and manage, one toggle per part of the panel. Ignored entirely while "Super admin" above is on.')
                ->schema([
                    Forms\Components\CheckboxList::make('granted_sections')
                        ->label('')
                        ->options(PanelSection::LABELS)
                        ->descriptions(PanelSection::DESCRIPTIONS)
                        ->columns(2),
                ])
                ->hidden(fn (Get $get): bool => (bool) $get('is_super_admin')),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_super_admin')
                    ->label('Super admin')
                    ->boolean(),

                Tables\Columns\TextColumn::make('granted_sections')
                    ->label('Sections')
                    ->formatStateUsing(fn (string $state): string => PanelSection::LABELS[$state] ?? $state)
                    ->badge()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->visible(fn (User $record): bool => $record->id !== auth()->id()),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
