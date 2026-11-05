<?php

namespace App\Identity;

use App\Actions\Identity\BrowserAccountSession;
use Illuminate\Auth\EloquentUserProvider;

final class LegacyUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return app(BrowserAccountSession::class)->allowsRestoring('web') ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return app(BrowserAccountSession::class)->allowsRestoring('web') ? parent::retrieveByToken($identifier, $token) : null;
    }

    protected function newModelQuery($model = null)
    {
        // Existing web/Filament compatibility must never email-resolve an
        // institution or platform principal through the historical namespace.
        return parent::newModelQuery($model)->where('principal_type', 'legacy');
    }
}
