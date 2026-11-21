<?php

namespace App\Providers\Filament;

use App\Filament\Auth\EditProfile;
use App\Filament\Theme;
use App\Http\Middleware\SendPanelRootHome;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return Theme::apply($panel)
            ->default()
            ->id('app')
            ->path('app')
            ->login()
            ->passwordReset()
            ->profile(EditProfile::class, isSimple: false)
            // Google sign-in and an invitation trust the address on an account, so a
            // new one counts only once its owner has opened the link mailed to it.
            ->emailChangeVerification()
            ->tenant(Tenant::class, slugAttribute: 'slug')
            ->discoverResources(in: app_path('Filament/App/Resources'), for: 'App\Filament\App\Resources')
            ->discoverPages(in: app_path('Filament/App/Pages'), for: 'App\Filament\App\Pages')
            ->globalSearch(false)
            ->userMenuItems([
                Action::make('admin')
                    ->label('Platform admin')
                    ->icon(Heroicon::OutlinedCog6Tooth)
                    ->url(fn (): string => Filament::getPanel('admin')->getUrl())
                    ->visible(fn (): bool => (bool) auth()->user()?->is_admin),
            ])
            ->discoverWidgets(in: app_path('Filament/App/Widgets'), for: 'App\Filament\App\Widgets')
            ->widgets([
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
                SendPanelRootHome::class,
            ]);
    }
}
