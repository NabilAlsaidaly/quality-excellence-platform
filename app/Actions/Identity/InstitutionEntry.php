<?php

namespace App\Actions\Identity;

use App\Identity\LoginEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class InstitutionEntry
{
    public function login(Request $request, string $organization, string $email, #[\SensitiveParameter] string $password): string
    {
        $organization = strtolower($organization);
        $key = 'institution-login:'.hash('sha256', $organization."\0".(LoginEmail::normalize($email) ?? $email)."\0".$request->ip());
        $message = 'تعذر تسجيل الدخول بالبيانات المحددة.';
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['email' => $message])->status(429)->redirectTo('/institution/login');
        }
        $guard = Auth::guard('institution');
        // Starting another login never preserves a previous institution session.
        $guard->logout();
        $request->session()->forget('institution.organization');
        if (! $guard->attempt(['organization' => $organization, 'email' => $email, 'password' => $password], false)) {
            RateLimiter::hit($key, 60);
            throw ValidationException::withMessages(['email' => $message])->redirectTo('/institution/login');
        }
        RateLimiter::clear($key);
        $request->session()->regenerate();
        $request->session()->put('institution.organization', $organization);
        $request->session()->put('institution.password_hash', $guard->user()->getAuthPassword());
        $request->session()->forget('url.intended');

        return $organization;
    }

    public function logout(Request $request): void
    {
        Auth::guard('institution')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
