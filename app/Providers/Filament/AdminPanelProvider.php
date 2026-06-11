<?php

namespace App\Providers\Filament;

use App\Http\Middleware\EnsurePlatformAdmin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
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
            // Match the frontend's design tokens (frontend/src/app/globals.css).
            // The primary ramp is explicit so shade 600 — what Filament's filled
            // buttons use — is EXACTLY the frontend's --primary button color
            // (oklch(0.43 0.16 16)); a generated palette would normalise it
            // lighter. 500 is the hover shade (frontend hovers at primary/90).
            ->colors([
                'primary' => [
                    50 => 'oklch(0.97 0.013 16)',
                    100 => 'oklch(0.94 0.03 16)',
                    200 => 'oklch(0.89 0.06 16)',
                    300 => 'oklch(0.81 0.1 16)',
                    400 => 'oklch(0.66 0.14 16)',
                    500 => 'oklch(0.48 0.16 16)',
                    600 => 'oklch(0.43 0.16 16)',
                    700 => 'oklch(0.38 0.15 16)',
                    800 => 'oklch(0.33 0.13 16)',
                    900 => 'oklch(0.28 0.11 16)',
                    950 => 'oklch(0.22 0.09 16)',
                ],
                'gray' => Color::Zinc,
            ])
            ->brandName('Terroir BI')
            ->brandLogo(asset('images/logo.png'))
            ->brandLogoHeight('8.5rem')
            // The frontend has no dark mode; keep the back office light to match.
            ->darkMode(false)
            ->renderHook(
                PanelsRenderHook::STYLES_AFTER,
                fn (): string => view('filament.theme.brand')->render(),
            )
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
                FilamentInfoWidget::class,
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
            ->authMiddleware([
                Authenticate::class,
                EnsurePlatformAdmin::class,
            ]);
    }
}
