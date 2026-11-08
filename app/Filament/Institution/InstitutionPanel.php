<?php

namespace App\Filament\Institution;

use App\Filament\Institution\Resources\ApproachResource;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/** Unregistered. Existing /institution entry and /admin remain unchanged. */
final class InstitutionPanel
{
    public static function make(): Panel
    {
        return Panel::make()->id('institution')->path('institution/workspace')->authGuard('institution')
            ->brandName('البيئة المؤسسية')->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(['primary' => Color::hex('#17324D')])->resources([ApproachResource::class])->pages([])->widgets([])
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
            ])->authMiddleware([Authenticate::class], isPersistent: true);
    }
}
