<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AiCosts;
use App\Filament\Pages\GeneralSettings;
use App\Filament\Pages\PanelUpdate;
use App\Filament\Pages\TelegramAlerts;
use App\Filament\Resources\ActivityLogResource;
use App\Filament\Resources\UserResource;
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
use Filament\View\PanelsRenderHook;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
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
            // The notification bell (NotificationBell) - rendered right before the user avatar
            // in the topbar, same spot "next to the account icon" describes. A real Livewire
            // component (not a static blade partial) since it needs the dropdown open/close and
            // mark-as-read interactivity - see App\Livewire\NotificationBell's own docblock.
            ->renderHook(
                PanelsRenderHook::USER_MENU_BEFORE,
                fn (): string => Blade::render('<livewire:notification-bell />'),
            )
            ->userMenuItems([
                // Each mirrors its page's own canAccess() so a user who can't open the page
                // never even sees the shortcut to it here - kept as a static call (not a
                // duplicated permission check) so the two can never drift apart.
                MenuItem::make()
                    ->label('Settings')
                    ->icon('heroicon-o-adjustments-horizontal')
                    ->url(fn () => GeneralSettings::getUrl())
                    ->visible(fn (): bool => GeneralSettings::canAccess()),
                // Registered after Settings (Filament renders custom user menu items in the order
                // they're added here, above the built-in Logout item) so this sits directly next
                // to "Sign out" in the dropdown, as asked for - moved out of General Settings
                // entirely rather than just linked from it.
                MenuItem::make()
                    ->label('AI Costs')
                    ->icon('heroicon-o-currency-dollar')
                    ->url(fn () => AiCosts::getUrl())
                    ->visible(fn (): bool => AiCosts::canAccess()),
                MenuItem::make()
                    ->label('Update')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->url(fn () => PanelUpdate::getUrl())
                    ->visible(fn (): bool => PanelUpdate::canAccess()),
                MenuItem::make()
                    ->label('Alerts')
                    ->icon('heroicon-o-bell-alert')
                    ->url(fn () => TelegramAlerts::getUrl())
                    ->visible(fn (): bool => TelegramAlerts::canAccess()),
                // The former "Administration" sidebar group (Users, Activity Log) - both
                // resources have $shouldRegisterNavigation = false, so this dropdown is now the
                // only way to reach them (still fully routed/gated the same as before, just not
                // in the sidebar).
                MenuItem::make()
                    ->label('Users')
                    ->icon('heroicon-o-users')
                    ->url(fn () => UserResource::getUrl())
                    ->visible(fn (): bool => UserResource::canViewAny()),
                MenuItem::make()
                    ->label('Activity Log')
                    ->icon('heroicon-o-clock')
                    ->url(fn () => ActivityLogResource::getUrl())
                    ->visible(fn (): bool => ActivityLogResource::canViewAny()),
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
