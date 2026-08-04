<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AiCosts;
use App\Filament\Pages\GeneralSettings;
use App\Services\SettingsService;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\MenuItem;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->colors([
                // Admin-configurable (General Settings > Appearance) rather than fixed - falls
                // back to the default preset's hex if the settings table isn't reachable yet
                // (e.g. artisan commands that boot providers before the DB is configured).
                'primary' => Color::hex($this->accentColorHex()),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                Widgets\AccountWidget::class,
            ])
            ->userMenuItems([
                MenuItem::make()
                    ->label('Settings')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn () => GeneralSettings::getUrl()),
                // Registered after Settings (Filament renders custom user menu items in the order
                // they're added here, above the built-in Logout item) so this sits directly next
                // to "Sign out" in the dropdown, as asked for - moved out of General Settings
                // entirely rather than just linked from it.
                MenuItem::make()
                    ->label('AI Costs')
                    ->icon('heroicon-o-currency-dollar')
                    ->url(fn () => AiCosts::getUrl()),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    private function accentColorHex(): string
    {
        try {
            return app(SettingsService::class)->getAccentColorHex();
        } catch (\Throwable) {
            return SettingsService::ACCENT_COLOR_PRESETS['signal-blue']['hex'];
        }
    }
}
