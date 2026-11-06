<?php

namespace App\Identity;

use App\Actions\Identity\BrowserAccountSession;
use App\Models\Organization;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Str;

final class InstitutionUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        return app(BrowserAccountSession::class)->allowsRestoring('institution') ? parent::retrieveById($identifier) : null;
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        return app(BrowserAccountSession::class)->allowsRestoring('institution') ? parent::retrieveByToken($identifier, $token) : null;
    }

    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)->where('principal_type', 'institution')
            ->whereNotNull('organization_id')
            ->whereHas('memberships', fn ($query) => $query->whereColumn('organization_memberships.organization_id', 'users.organization_id'));
    }

    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials)
    {
        $organization = $credentials['organization'] ?? null;
        $organization = is_string($organization) ? strtolower($organization) : null;
        $email = is_string($credentials['email'] ?? null) ? LoginEmail::normalize($credentials['email']) : null;
        if (! is_string($organization) || ! Str::isUlid($organization) || $email === null) {
            return null;
        }
        $query = $this->newModelQuery()->where('email', $email)
            ->whereIn('organization_id', Organization::query()->select('id')->where('public_id', $organization));
        if (isset($credentials['public_id'])) {
            if (! is_string($credentials['public_id']) || ! Str::isUlid($credentials['public_id'])) {
                return null;
            }
            $query->where('public_id', $credentials['public_id']);
        }

        if (($credentials['_locked'] ?? false) === true) {
            $query->lockForUpdate();
        }

        return $query->first();
    }
}
