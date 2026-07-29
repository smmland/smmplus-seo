<?php

namespace App\Filament\Pages;

use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class GeneralSettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-adjustments-horizontal';

    protected static ?string $navigationLabel = 'General Settings';

    protected static ?string $title = 'General Settings';

    protected static string $view = 'filament.pages.general-settings';

    protected static ?int $navigationSort = -1;

    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'email' => Auth::user()->email,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TextInput::make('email')
                    ->label('Admin email')
                    ->email()
                    ->required(),

                TextInput::make('newPassword')
                    ->label('New password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->helperText('Leave this field empty if you don\'t want to change the password.'),

                TextInput::make('currentPassword')
                    ->label('Current password')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('Enter your current password to confirm these changes.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Auth::user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            Notification::make()
                ->title('Current password is incorrect')
                ->danger()
                ->send();

            return;
        }

        $user->email = $data['email'];

        if (filled($data['newPassword'] ?? null)) {
            $user->password = $data['newPassword'];
        }

        $user->save();

        $this->form->fill([
            'email' => $user->email,
            'newPassword' => null,
            'currentPassword' => null,
        ]);

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
