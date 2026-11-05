<?php

namespace App\Authorization;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final class TenantAuthorization
{
    public function __construct(
        private TenantContext $context,
        private CapabilityRegistry $capabilities,
    ) {}

    public function allows(?User $actor, string $capability, Organization|OrganizationalUnit $resource): bool
    {
        if (! $this->context->verifiedFor($actor)
            || $this->capabilities->resourceClass($capability) !== $resource::class
            || ! $resource->exists) {
            return false;
        }

        // Reload persisted scope; caller-modified model attributes grant nothing.
        $organizationId = $this->context->organizationId();
        $scoped = $resource instanceof Organization
            ? Organization::query()->whereKey($resource->getKey())->whereKey($organizationId)->exists()
            : OrganizationalUnit::query()->whereKey($resource->getKey())
                ->where('organization_id', $organizationId)->exists();

        if (! $scoped) {
            return false;
        }

        // Query package relationships rather than stale loaded user/definition caches.
        // Direct user permissions deliberately do not grant application capabilities.
        return $actor->roles()->where('roles.organization_id', $organizationId)
            ->where('roles.guard_name', 'web')
            ->whereHas('permissions', fn ($query) => $query->where('permissions.name', $capability)
                ->where('permissions.guard_name', 'web'))->exists();
    }

    public function authorize(?User $actor, string $capability, Organization|OrganizationalUnit $resource): void
    {
        if (! $this->allows($actor, $capability, $resource)) {
            throw new AuthorizationException;
        }
    }
}
