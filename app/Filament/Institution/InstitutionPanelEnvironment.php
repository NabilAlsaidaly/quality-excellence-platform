<?php

namespace App\Filament\Institution;

use Closure;
use Illuminate\Http\Request;

final class InstitutionPanelEnvironment
{
    public function handle(Request $request, Closure $next)
    {
        if (! app()->environment(['local', 'testing'])) {
            return response('غير متاح.', 404, ['Cache-Control' => 'no-store, private']);
        }
        if (strtolower($request->getHost()) !== strtolower((string) parse_url(config('app.url'), PHP_URL_HOST))) {
            return response('عنوان الطلب غير صالح.', 400, ['Cache-Control' => 'no-store, private']);
        }

        return $next($request);
    }
}
