<?php

namespace App\Filament\Institution;

use App\Filament\Institution\Pages\InstitutionDashboard;
use App\Filament\Institution\Resources\ApproachResource;
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

/** Registered definition; runtime access is local/testing AND explicitly authorized. */
final class InstitutionPanel
{
    public static function make(?Panel $panel = null): Panel
    {
        return ($panel ?? Panel::make())->id('institution')->path('institution/workspace')->authGuard('institution')
            ->brandName('البيئة المؤسسية')->viteTheme('resources/css/filament/admin/theme.css')
            ->colors(['primary' => Color::hex('#17324D')])->resources([ApproachResource::class])->pages([InstitutionDashboard::class])->widgets([])
            ->middleware([
                InstitutionPanelEnvironment::class,
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                InstitutionPanelSession::class,
                AuthenticateSession::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])->persistentMiddleware([InstitutionPanelEnvironment::class, InstitutionPanelSession::class])
            ->authMiddleware([InstitutionAuthenticate::class], isPersistent: true);
    }
}
