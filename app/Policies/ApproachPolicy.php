<?php

namespace App\Policies;

use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;

/** Candidate only. No approved D002 assignment store or operational grant exists. */
class ApproachPolicy
{
    public const OPERATIONS = ['list', 'create', 'view', 'updateMetadata', 'changeOwner', 'editDraft', 'viewDraftHistory', 'viewAuditHistory'];

    public function before(User $actor, string $ability): ?bool
    {
        return in_array($ability, ['viewAny', 'create', 'view', 'update'], true) ? null : false;
    }

    final public function allowsOperation(User $actor, string $operation, ?Approach $resource = null): bool
    {
        $context = app(TenantContext::class);
        $current = User::query()->find($actor->getKey());
        if (! in_array($operation, self::OPERATIONS, true) || ! $context->verifiedFor($actor)
            || $current?->principal_type !== 'institution'
            || (int) $current->organization_id !== $context->organizationId()) {
            return false;
        }
        if ($resource !== null && (! $resource->exists || ! Approach::query()->whereKey($resource->getKey())
            ->where('organization_id', $context->organizationId())->where('public_id', $resource->public_id)->exists())) {
            return false;
        }
        if (! in_array($operation, ['list', 'create'], true) && $resource === null) {
            return false;
        }
        $organization = Organization::query()->find($context->organizationId());

        // Intentionally unregistered. Tests register this structural predicate only
        // in isolated fixtures, then independently simulate per-resource assignments.
        return $organization !== null
            && app(TenantAuthorization::class)->allows($actor, 'approaches.access', $organization)
            && $this->hasAssignment($current, $operation, $resource);
    }

    protected function hasAssignment(User $actor, string $operation, ?Approach $resource): bool
    {
        return false;
    }

    public function viewAny(User $actor): bool
    {
        return $this->allowsOperation($actor, 'list');
    }

    public function create(User $actor): bool
    {
        return $this->allowsOperation($actor, 'create');
    }

    public function view(User $actor, Approach|string $resource): bool
    {
        // Existing inert HTTP adapters pass class + public ULID to Gate. They
        // remain denied; never turn an unscoped string into a trusted model.
        return $resource instanceof Approach && $this->allowsOperation($actor, 'view', $resource);
    }

    public function update(User $actor, Approach|string $resource): bool
    {
        return $resource instanceof Approach && $this->allowsOperation($actor, 'updateMetadata', $resource);
    }

    public function delete(User $actor, Approach $resource): bool
    {
        return false;
    }

    public function approve(User $actor, Approach $resource): bool
    {
        // Review engine and independent reviewer assignments are outside Issue44.
        return false;
    }
}
