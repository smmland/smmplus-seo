<?php

namespace App\Filament\Pages;

use App\Services\GatewaySettingsService;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

class GatewaySettings extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?string $navigationGroup = 'Free Service Gateway';

    protected static ?string $navigationLabel = 'Settings';

    protected static string $view = 'filament.pages.gateway-settings';

    public ?array $data = [];

    public function mount(GatewaySettingsService $settings): void
    {
        $this->form->fill([
            'allowedOrigins' => $settings->getAllowedOrigins(),
            'globalDailySeconds' => $settings->getGlobalDailySeconds(),
            'globalDailyIpLimit' => $settings->getGlobalDailyIpLimit(),
            'globalDailyTargetLimit' => $settings->getGlobalDailyTargetLimit(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                TagsInput::make('allowedOrigins')
                    ->label('Allowed origins')
                    ->placeholder('https://smm.plus')
                    ->helperText('Only requests with an Origin header matching one of these are allowed to call the gateway from a browser.')
                    ->required(),

                TextInput::make('globalDailySeconds')
                    ->label('Global daily window (seconds)')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('How long the global per-IP/per-target daily caps below apply for. Defaults to 86400 (24 hours).'),

                TextInput::make('globalDailyIpLimit')
                    ->label('Global daily limit per IP')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Max free orders (across all services) one IP can place within the window, regardless of quantity.'),

                TextInput::make('globalDailyTargetLimit')
                    ->label('Global daily limit per target')
                    ->numeric()
                    ->minValue(1)
                    ->required()
                    ->helperText('Max free orders (across all services) one link/username can receive within the window.'),
            ])
            ->statePath('data');
    }

    public function save(GatewaySettingsService $settings): void
    {
        $data = $this->form->getState();

        $settings->setAllowedOrigins($data['allowedOrigins']);
        $settings->setGlobalDailyLimits(
            (int) $data['globalDailySeconds'],
            (int) $data['globalDailyIpLimit'],
            (int) $data['globalDailyTargetLimit'],
        );

        Notification::make()
            ->title('Settings saved')
            ->success()
            ->send();
    }
}
