<?php

namespace App\Actions\Organizations;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * @internal Trusted platform-side application code only; no user-facing caller.
 * A persisted actor is audit attribution, NOT platform-administration authority.
 * Future entry points MUST enforce an owner-approved management Policy first.
 */
final class OrganizationProvisioning
{
    public function create(?User $actor, string $name): Organization
    {
        return DB::transaction(function () use ($actor, $name): Organization {
            $actorId = $this->currentActorId($actor);
            $organization = new Organization(['name' => $this->validName($name)]);
            $organization->save();
            $this->record($organization, $actorId, 'created', null);

            return $organization;
        });
    }

    public function rename(?User $actor, string $publicId, string $name): Organization
    {
        $this->requireActorShape($actor);

        return DB::transaction(function () use ($actor, $publicId, $name): Organization {
            // Organization first, then actor: compatible with existing hierarchy
            // operations. Current read avoids stale previous-name snapshots.
            $organization = Str::isUlid($publicId)
                ? Organization::query()->where('public_id', $publicId)->lockForUpdate()->first()
                : null;
            if ($organization === null) {
                throw (new ModelNotFoundException)->setModel(Organization::class);
            }
            $actorId = $this->currentActorId($actor);
            $name = $this->validName($name);
            if ($organization->name === $name) {
                return $organization;
            }
            $previous = $organization->name;
            $organization->name = $name;
            $organization->save();
            $this->record($organization, $actorId, 'renamed', $previous);

            return $organization;
        });
    }

    private function requireActorShape(?User $actor): void
    {
        if ($actor === null || ! $actor->exists || $actor->getKey() === null) {
            throw new AuthorizationException;
        }
    }

    private function currentActorId(?User $actor): int
    {
        $this->requireActorShape($actor);
        $current = User::query()->whereKey($actor->getKey())->sharedLock()->first(['id']);
        if ($current === null) {
            throw new AuthorizationException;
        }

        return (int) $current->getKey();
    }

    private function validName(string $name): string
    {
        // Preserve exact UTF-8 text. No trimming, case-folding or normalization.
        if (preg_match('/[^\\p{Z}\\s]/u', $name) !== 1 || mb_strlen($name) > 255) {
            throw ValidationException::withMessages(['name' => 'A name of 1 to 255 characters is required.']);
        }

        return $name;
    }

    private function record(Organization $organization, int $actorId, string $event, ?string $previous): void
    {
        DB::table('organization_changes')->insert([
            'organization_id' => $organization->id, 'actor_id' => $actorId, 'event' => $event,
            'previous_name' => $previous, 'new_name' => $organization->name, 'created_at' => now(),
        ]);
    }
}
