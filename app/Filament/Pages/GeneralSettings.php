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

    protected static ?string $navigationLabel = 'تنظیمات کلی';

    protected static ?string $title = 'تنظیمات کلی';

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
                    ->label('ایمیل ادمین')
                    ->email()
                    ->required(),

                TextInput::make('newPassword')
                    ->label('رمز عبور جدید')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->helperText('در صورتی که نمی‌خواهید رمز عبور تغییر کند، این فیلد را خالی بگذارید.'),

                TextInput::make('currentPassword')
                    ->label('رمز عبور فعلی')
                    ->password()
                    ->revealable()
                    ->required()
                    ->helperText('برای تایید تغییرات، رمز عبور فعلی خود را وارد کنید.'),
            ])
            ->statePath('data');
    }

    public function save(): void
    {
        $data = $this->form->getState();

        $user = Auth::user();

        if (! Hash::check($data['currentPassword'], $user->password)) {
            Notification::make()
                ->title('رمز عبور فعلی اشتباه است')
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
            ->title('تنظیمات ذخیره شد')
            ->success()
            ->send();
    }
}
