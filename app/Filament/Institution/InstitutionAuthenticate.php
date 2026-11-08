<?php

namespace App\Filament\Institution;

use App\Actions\Identity\InstitutionEntry;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Support\Facades\Auth;

final class InstitutionAuthenticate extends Authenticate
{
    public function handle($request, \Closure $next, ...$guards)
    {
        // Framework priority places native Authenticate before the session
        // bridge. CSRF and the runtime environment/host gate run before this.
        if ($request->routeIs('filament.institution.auth.logout')) {
            app(InstitutionEntry::class)->logout($request);

            return redirect('/institution/login')->withHeaders(['Cache-Control' => 'no-store, private']);
        }
        $actor = Auth::guard('institution')->user();
        if ($actor !== null && ! $actor->canAccessPanel(Filament::getPanel('institution'))) {
            return response('الوصول غير مسموح.', 403, ['Cache-Control' => 'no-store, private']);
        }

        return parent::handle($request, $next, ...$guards);
    }

    protected function redirectTo($request): ?string
    {
        return '/institution/login';
    }
}
