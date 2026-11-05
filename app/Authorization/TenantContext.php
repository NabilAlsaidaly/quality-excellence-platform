<?php

namespace App\Authorization;

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;

final class TenantContext
{
    private ?int $organizationId = null;

    private ?int $actorId = null;

    public function organizationId(): ?int
    {
        return $this->organizationId;
    }

    public function actorId(): ?int
    {
        return $this->actorId;
    }

    public function clear(): void
    {
        $this->actorId = null;
        $this->organizationId = null;
    }

    public function verifiedFor(?User $actor): bool
    {
        return $actor !== null && $actor->exists
            && $this->actorId !== null && $this->organizationId !== null
            && (int) $actor->getKey() === $this->actorId
            && User::query()->whereKey($this->actorId)->where(fn ($query) => $query
                ->where('principal_type', 'legacy')
                ->orWhere(fn ($query) => $query->where('principal_type', 'institution')->where('organization_id', $this->organizationId)))->exists()
            && Organization::query()->whereKey($this->organizationId)->exists()
            && OrganizationMembership::query()->where('user_id', $this->actorId)
                ->where('organization_id', $this->organizationId)->exists();
    }

    /**
     * Internal execution boundary. Actor must come from trusted authentication
     * or a reloaded job identity, never a submitted user ID.
     */
    public function run(?User $actor, int|string $organization, Closure $operation): mixed
    {
        $previous = [$this->actorId, $this->organizationId];
        $this->clearRelations($actor);
        $this->actorId = null;
        $this->organizationId = null;

        try {
            // Strings are public ULIDs; integers are trusted internal keys.
            $query = Organization::query();
            $tenant = is_int($organization)
                ? $query->whereKey($organization)->first()
                : $query->where('public_id', $organization)->first();
            if ($actor === null || ! $actor->exists || $tenant === null) {
                throw new AuthorizationException;
            }
            $this->actorId = (int) $actor->getKey();
            $this->organizationId = (int) $tenant->getKey();
            if (! $this->verifiedFor($actor)) {
                throw new AuthorizationException;
            }

            return $operation();
        } finally {
            $this->clearRelations($actor);
            [$this->actorId, $this->organizationId] = $previous;
        }
    }

    private function clearRelations(?User $actor): void
    {
        $actor?->unsetRelation('roles')->unsetRelation('permissions');
    }
}
