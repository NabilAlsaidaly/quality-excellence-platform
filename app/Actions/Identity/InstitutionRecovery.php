<?php

namespace App\Actions\Identity;

use App\Identity\LoginEmail;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\InstitutionPasswordReset;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Support\Timebox;

final class InstitutionRecovery
{
    public function request(string $organization, string $email, string $ip = ''): void
    {
        $organization = strtolower($organization);
        $key = 'institution-recovery:'.hash('sha256', $organization."\0".(LoginEmail::normalize($email) ?? $email)."\0".$ip);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return;
        }
        RateLimiter::hit($key, 60);
        (new Timebox)->call(function () use ($organization, $email): void {
            $credentials = ['organization' => $organization, 'email' => $email, '_locked' => true];
            $account = Auth::createUserProvider('institution')->retrieveByCredentials(array_diff_key($credentials, ['_locked' => true]));
            if ($account === null) {
                return;
            }
            DB::transaction(function () use ($account, $credentials, $organization, $email): void {
                $current = User::query()->whereKey($account->id)->lockForUpdate()->firstOrFail();
                if ($current->email !== LoginEmail::normalize($email) || ! $this->currentMember($current)) {
                    return;
                }
                Password::broker('institution')->sendResetLink($credentials, function ($current, $token) use ($organization): void {
                    DB::afterCommit(fn () => $current->notify(new InstitutionPasswordReset($organization, $current->public_id, $token)));
                });
            });
        }, config('auth.timebox_duration', 200000));
    }

    public function reset(string $organization, string $publicId, #[\SensitiveParameter] string $token, #[\SensitiveParameter] string $password): bool
    {
        if (! Str::isUlid($publicId) || ! Str::isUlid($organization)) {
            return false;
        }

        return DB::transaction(function () use ($organization, $publicId, $token, $password): bool {
            // Serialize issuance and consumption on the same stable account row.
            $account = User::query()->where('public_id', $publicId)->lockForUpdate()->first();
            if ($account === null || ! $this->currentMember($account)) {
                return false;
            }
            $status = Password::broker('institution')->reset([
                'organization' => $organization, 'public_id' => $publicId, 'email' => $account->email,
                'token' => $token, 'password' => $password, '_locked' => true,
            ], function (User $current, string $password): void {
                $current->password = $password;
                $current->setRememberToken(Str::random(60));
                $current->save();
                event(new PasswordReset($current));
            });

            return $status === Password::PASSWORD_RESET;
        });
    }

    private function currentMember(User $user): bool
    {
        return $user->principal_type === 'institution' && OrganizationMembership::query()
            ->where('user_id', $user->id)->where('organization_id', $user->organization_id)->lockForUpdate()->first() !== null;
    }
}
