<?php

namespace App\Http\Middleware;

use App\Actions\Identity\InstitutionEntry;
use App\Authorization\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

final class InstitutionSession
{
    public function handle(Request $request, Closure $next, string $mode = 'account')
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        // Bound mail/reset origin and redirects must not trust an arbitrary Host.
        abort_unless(strtolower($request->getHost()) === strtolower((string) parse_url(config('app.url'), PHP_URL_HOST)),
            400, 'عنوان الطلب غير صالح.');
        $context = app(TenantContext::class);
        $context->clear();
        if ($mode === 'public') {
            try {
                return $this->privateResponse($next($request));
            } finally {
                $context->clear();
            }
        }
        $guard = Auth::guard('institution');
        $cached = $guard->user();
        $user = $cached ? $guard->getProvider()->retrieveById($cached->getAuthIdentifier()) : null;
        if ($user !== null) {
            $guard->setUser($user);
        }
        $organization = $request->route('organization');
        try {
            if ($user === null || ! is_string($organization)
                || $request->session()->get('identity.active_guard') !== 'institution'
                || $request->session()->get('institution.organization') !== $organization
                || ! hash_equals((string) $user->getAuthPassword(), (string) $request->session()->get('institution.password_hash'))) {
                throw new AuthorizationException;
            }

            return $this->privateResponse($context->run($user, $organization, function () use ($user, $organization, $next, $request) {
                Gate::forUser($user)->authorize('institution-account', $organization);

                return $next($request);
            }));
        } catch (AuthorizationException) {
            app(InstitutionEntry::class)->logout($request);

            return $this->privateResponse(redirect('/institution/login'));
        } finally {
            $context->clear();
            $user?->unsetRelation('roles')->unsetRelation('permissions');
        }
    }

    private function privateResponse($response)
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }
}
