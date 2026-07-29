<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Http\Middleware\BindCompanyContext;
use App\Http\Middleware\SetLocale;
use App\Models\Company;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Blade;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Injected as a plain stylesheet rather than compiled through Vite so
        // that deploying does not require a Node toolchain. It overrides
        // Filament's own variables, so it has to load after the panel's CSS —
        // which the end of <head> guarantees.
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_END,
            fn (): string => Blade::render(
                '<link rel="stylesheet" href="{{ asset(\'css/erp-theme.css\') }}?v={{ $version }}">',
                // Cache-busted by file mtime, so a theme change is visible
                // without asking anyone to hard-refresh.
                ['version' => @filemtime(public_path('css/erp-theme.css')) ?: '1'],
            ),
        );
    }

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
