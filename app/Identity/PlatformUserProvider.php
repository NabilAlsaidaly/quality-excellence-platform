<?php

namespace App\Identity;

use Illuminate\Auth\EloquentUserProvider;

/** Unregistered candidate. Registering a guard or login flow requires separate review. */
final class PlatformUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->where('principal_type', 'platform')->whereNull('organization_id');
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $email = is_string($credentials['email'] ?? null) ? LoginEmail::normalize($credentials['email']) : null;
        if ($email === null || array_diff(array_keys($credentials), ['email', 'password']) !== []) {
            return null;
        }

        return $this->newModelQuery()->where('email', $email)->first();
    }

    public function retrieveById($identifier)
    {
        // Never restore a competing legacy/institution context or an unmarked
        // session. A future reviewed login must set this marker explicitly.
        return $this->canRestore() ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return $this->canRestore() ? parent::retrieveByToken($identifier, $token) : null;
    }

    private function canRestore(): bool
    {
        return app('session.store')->get('identity.active_guard') === 'platform'
            && ! app('session.store')->has('institution.organization');
    }
}
