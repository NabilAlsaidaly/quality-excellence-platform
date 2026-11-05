<?php

namespace App\Actions\Identity;

use App\Authorization\TenantContext;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class BrowserAccountSession
{
    public function end(Request $request): void
    {
        foreach (['web', 'institution'] as $guard) {
            // Clear this browser's guard/cookie without rotating other devices'
            // shared remember token or deleting their authenticated sessions.
            Auth::guard($guard)->logoutCurrentDevice();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        app(TenantContext::class)->clear();
    }

    public function authenticated(Login $event): void
    {
        if (! in_array($event->guard, ['web', 'institution'], true)) {
            return;
        }
        // Use the actual guard store, including Filament/Livewire updates whose
        // request adapter may not expose a session on the Request object.
        $session = Auth::guard($event->guard)->getSession();
        Auth::guard($event->guard === 'web' ? 'institution' : 'web')->logoutCurrentDevice();
        $session->forget(['institution', 'url.intended']);
        $session->put('identity.active_guard', $event->guard);
        // Laravel's SessionGuard already regenerates the ID/CSRF on login.
        app(TenantContext::class)->clear();
    }

    public function allowsRestoring(string $guard): bool
    {
        $session = app('session.store');
        $active = $session->get('identity.active_guard');
        // Older candidate sessions may have institutional state but no marker.
        if ($active === null && $session->has('institution.organization')) {
            $active = 'institution';
        }

        return $active === null || $active === $guard;
    }
}
