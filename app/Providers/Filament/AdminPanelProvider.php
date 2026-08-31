<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Pages\Dashboard;
use App\Http\Middleware\BindCompanyContext;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
            ->profile()
            ->colors([
                'primary' => Color::Emerald,
                'gray' => Color::Slate,
            ])
            // Compiled by Vite, which is what makes it the panel's theme rather
            // than a stylesheet layered over one. Filament loads it in place of
            // its own, so the variable overrides below take effect without
            // depending on where in <head> they land.
            ->viteTheme('resources/css/filament/admin/theme.css')
            // Light by default. Accountants read dense numeric tables for long
            // stretches, and a light working surface is easier for that; the
            // contrast comes from the dark sidebar instead. Users may still
            // switch, so dark mode is styled rather than disabled.
            ->defaultThemeMode(ThemeMode::Light)
            ->brandName('ERP Platform')
            ->sidebarCollapsibleOnDesktop()
            // Company selection is part of the URL, and Filament verifies
            // membership through User::canAccessTenant() before any page loads.
            ->tenant(Company::class, slugAttribute: 'id')
            ->tenantMiddleware([
                BindCompanyContext::class,
            ], isPersistent: true)
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            // Qoyod's sidebar order. Groups render in this sequence; a group
            // with no visible items (quotations before the purchases slice,
            // say) simply does not appear, so the list can name the whole
            // target layout ahead of the modules that will fill it.
            // The labels are closures because panel() runs before SetLocale
            // has read the user's language; a plain __() here would freeze the
            // group names in the default locale and the per-request labels the
            // resources emit would no longer match their groups.
            ->navigationGroups([
                NavigationGroup::make()->label(fn (): string => __('sales.navigation_group')),
                NavigationGroup::make()->label(fn (): string => __('purchases.navigation_group')),
                NavigationGroup::make()->label(fn (): string => __('sales.products_group')),
                NavigationGroup::make()->label(fn (): string => __('assets.navigation_group')),
                NavigationGroup::make()->label(fn (): string => __('accounting.navigation_group')),
                NavigationGroup::make()->label(fn (): string => __('accounting.reports_group')),
                NavigationGroup::make()->label(fn (): string => __('identity.navigation_group')),
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ])
            ->authMiddleware([
                Authenticate::class,
                // Runs after authentication so the user's own preference is
                // available; this is what flips the panel between RTL and LTR.
                SetLocale::class,
            ]);
    }
}
