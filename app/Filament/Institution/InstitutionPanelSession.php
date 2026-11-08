<?php

namespace App\Filament\Institution;

use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Authenticated HTTP/persistent Livewire proof, not an institution grant. */
final class InstitutionPanelSession
{
    public function handle(Request $request, Closure $next)
    {
        $headers = ['Cache-Control' => 'no-store, private', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'];
        if (! app()->environment(['local', 'testing'])) {
            return response('غير متاح.', 404, $headers);
        }
        $context = app(TenantContext::class);
        $context->clear();
        $originalGuard = Auth::getDefaultDriver();
        try {
            $guard = Auth::guard('institution');
            $cached = $guard->user();
            $actor = $cached ? $guard->getProvider()->retrieveById($cached->getAuthIdentifier()) : null;
            if ($actor === null && ! $request->session()->has('identity.active_guard')) {
                return $request->expectsJson() ? response()->json(['message' => 'يلزم تسجيل الدخول.'], 401, $headers)
                    : redirect('/institution/login')->withHeaders($headers);
            }
            if ($actor === null || ! app(ApproachAccess::class)->canEnter($actor)) {
                return response('الوصول غير مسموح.', 403, $headers);
            }
            $guard->setUser($actor);
            Auth::shouldUse('institution');
            $response = $context->run($actor, $request->session()->get('institution.organization'), fn () => $next($request));
            $response->headers->add($headers);

            return $response;
        } catch (AuthorizationException) {
            return response('الوصول غير مسموح.', 403, $headers);
        } finally {
            $context->clear();
            Auth::shouldUse($originalGuard);
        }
    }
}
