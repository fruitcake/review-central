<?php

declare(strict_types=1);

namespace App\Filament;

use App\Services\Broadcasts;
use App\Services\PlatformSettings;
use App\Services\SingleSignOn;
use Filament\FontProviders\LocalFontProvider;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;

/**
 * The look both panels share: white cards on a light slate ground, square and
 * flat, one deep teal sidebar and a teal accent, set in the reader's own
 * interface font.
 *
 * The type, the corners and the layout are the same for every tenant. Its colours
 * a tenant can set: see Themes.
 */
class Theme
{
    public static function apply(Panel $panel, string $subtitle = ''): Panel
    {
        return $panel
            ->colors([
                // 600 is what coloured text, a focus ring and a switch are drawn in:
                // the accent itself, which reads on white.
                'primary' => [
                    50 => '#f0fdfa', 100 => '#ccfbf1', 200 => '#99f6e4', 300 => '#5eead4', 400 => '#2dd4bf',
                    500 => '#14b8a6', 600 => '#0f766e', 700 => '#115e59', 800 => '#134e4a', 900 => '#042f2e', 950 => '#021c1b',
                ],
                'danger' => Color::Red,
                'success' => Color::Green,
                'warning' => Color::Amber,
                'info' => Color::Slate,
                'gray' => Color::Slate,
            ])
            // No font is loaded: the stylesheet names the reader's own interface font.
            ->font('system-ui', provider: LocalFontProvider::class)
            // The design is drawn on a light ground only.
            ->darkMode(false)
            ->topbar(false)
            ->sidebarWidth('16rem')
            ->maxContentWidth('7xl')
            // The installation's own name and logo, set under Settings.
            ->brandName(fn (): string => trim(app(PlatformSettings::class)->appName().($subtitle !== '' ? ' admin' : '')))
            ->brandLogo(fn (): View => view('filament.brand', [
                'name' => app(PlatformSettings::class)->appName(),
                'logo' => app(PlatformSettings::class)->logo(),
                'subtitle' => $subtitle,
            ]))
            ->brandLogoHeight('2rem')
            ->favicon(fn (): string => app(PlatformSettings::class)->favicon())
            ->renderHook(PanelsRenderHook::SIDEBAR_FOOTER, fn (): string|View => app(PlatformSettings::class)->credit() ? view('filament.credit') : '')
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.sign-in-with', ['providers' => SingleSignOn::providers()]))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): View => view('filament.theme', ['admin' => $subtitle !== '', 'colours' => Themes::for(CurrentTenant::find())]))
            // Filament's own Echo is left off: it does not know Mercure.
            ->renderHook(PanelsRenderHook::SCRIPTS_AFTER, fn (): View => view('filament.echo', ['options' => Broadcasts::echoOptions()]));
    }
}
