<?php

namespace App\Identity;

use App\Models\User;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Carbon;
use LogicException;

final class AccountTokenRepository extends DatabaseTokenRepository
{
    public function create(CanResetPassword $user)
    {
        $this->requireInstitution($user);
        $this->delete($user);
        $token = $this->createNewToken();
        $this->getTable()->insert(['account_id' => $user->getKey(), 'organization_id' => $user->organization_id,
            'token' => $this->hasher->make($token), 'credential_hash' => $this->credentialHash($user), 'created_at' => new Carbon]);

        return $token;
    }

    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token)
    {
        $this->requireInstitution($user);
        $record = $this->accountQuery($user)->first();

        return $record !== null && ! $this->tokenExpired($record->created_at)
            && hash_equals($record->credential_hash, $this->credentialHash($user))
            && is_string($token) && $this->hasher->check($token, $record->token);
    }

    public function recentlyCreatedToken(CanResetPassword $user)
    {
        $this->requireInstitution($user);
        $record = $this->accountQuery($user)->first();

        return $record !== null && $this->tokenRecentlyCreated($record->created_at);
    }

    public function delete(CanResetPassword $user)
    {
        $this->requireInstitution($user);
        $this->accountQuery($user)->delete();
    }

    private function accountQuery(User $user)
    {
        // Current locking read, including callers with an older InnoDB snapshot.
        return $this->getTable()->where('account_id', $user->getKey())->where('organization_id', $user->organization_id)->lockForUpdate();
    }

    private function credentialHash(User $user): string
    {
        return hash('sha256', $user->getAuthPassword()."\0".$user->email);
    }

    private function requireInstitution(CanResetPassword $user): void
    {
        if (! $user instanceof User || $user->principal_type !== 'institution' || ! $user->organization_id) {
            throw new LogicException('Institution recovery requires an institutional account.');
        }
    }
}
