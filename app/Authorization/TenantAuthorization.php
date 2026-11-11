<?php

namespace App\Authorization;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class TenantAuthorization
{
    /** Only populated after this boundary acquires the actual organization lock. */
    private array $mutations = [];

    public function __construct(
        private TenantContext $context,
        private CapabilityRegistry $capabilities,
    ) {}

    public function allows(?User $actor, string $capability, Organization|OrganizationalUnit $resource): bool
    {
        $proof = end($this->mutations);
        if ($proof !== false) {
            return $this->currentAllows($actor, $capability, $resource, $proof);
        }

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

    /**
     * @internal Structural eligibility only, never an operation grant. Supported
     * writers must enter before taking domain/role locks and authorize inside.
     * Order: Organization X -> ascending Users X -> ascending memberships X.
     * The callback, mutation and audit share this PDO and outer transaction.
     *
     * @param  list<User>  $subjects  Trusted internal role-management targets only.
     */
    public function withinMutation(?User $actor, Closure $operation, array $subjects = []): mixed
    {
        $organizationId = $this->context->organizationId();
        $actorId = $this->context->actorId();
        if ($actor === null || ! $actor->exists || $organizationId === null || $actorId !== (int) $actor->getKey()) {
            throw new AuthorizationException;
        }
        $ids = [$actorId];
        foreach ($subjects as $subject) {
            if (! $subject instanceof User || ! $subject->exists) {
                throw new AuthorizationException;
            }
            $ids[] = (int) $subject->getKey();
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NUMERIC);
        $connection = DB::connection();
        try {
            return $connection->transaction(function () use ($connection, $organizationId, $actorId, $ids, $operation): mixed {
                $organization = Organization::query()->whereKey($organizationId)->lockForUpdate()->first();
                $users = [];
                foreach ($ids as $id) {
                    $users[$id] = User::query()->whereKey($id)->lockForUpdate()->first();
                }
                if ($organization === null) {
                    throw new AuthorizationException;
                }
                // Individually lock membership PKs in deterministic order.
                $membershipIds = OrganizationMembership::query()->where('organization_id', $organizationId)
                    ->whereIn('user_id', $ids)->orderBy('id')->pluck('id');
                $memberships = collect();
                foreach ($membershipIds as $id) {
                    $membership = OrganizationMembership::query()->whereKey($id)->where('organization_id', $organizationId)->lockForUpdate()->first();
                    if ($membership !== null) {
                        $memberships->put($membership->user_id, $membership);
                    }
                }
                foreach ($users as $id => $user) {
                    if ($user === null || ! $memberships->has($id)
                        || ! ($user->principal_type === 'legacy' || ($user->principal_type === 'institution'
                            && (int) $user->organization_id === $organizationId))) {
                        throw new AuthorizationException;
                    }
                }
                $proof = ['connection' => $connection, 'pdo' => $connection->getPdo(), 'organization' => $organization,
                    'actor' => $users[$actorId], 'level' => $connection->transactionLevel()];
                $this->mutations[] = $proof;
                try {
                    $result = $operation($organization, $users[$actorId]);
                    if (! $this->validMutation($proof)) {
                        throw new AuthorizationException;
                    }

                    return $result;
                } finally {
                    array_pop($this->mutations);
                }
            }, 1);
        } catch (Throwable $error) {
            // A lock timeout ordinarily rolls back only its SQL statement. Never
            // let a caller commit earlier application writes after that failure.
            // Laravel13 wraps nested concurrency QueryExceptions in a
            // DeadlockException. Preserve that exception and its original chain.
            $lockFailure = false;
            for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
                if ($cause instanceof QueryException && in_array((int) ($cause->errorInfo[1] ?? 0), [1205, 1213], true)) {
                    $lockFailure = true;
                    break;
                }
            }
            if ($lockFailure && $connection->transactionLevel() > 0) {
                $connection->rollBack(0);
            }
            throw $error;
        }
    }

    private function validMutation(array $proof): bool
    {
        return DB::connection() === $proof['connection'] && DB::connection()->getPdo() === $proof['pdo']
            && DB::transactionLevel() >= $proof['level']
            && $this->context->organizationId() === (int) $proof['organization']->id
            && $this->context->actorId() === (int) $proof['actor']->id;
    }

    private function currentAllows(?User $actor, string $capability, Organization|OrganizationalUnit $resource, array $proof): bool
    {
        if (! $this->validMutation($proof) || $actor === null || ! $actor->exists
            || (int) $actor->id !== (int) $proof['actor']->id || ! $resource->exists
            || $this->capabilities->resourceClass($capability) !== $resource::class) {
            return false;
        }
        $organizationId = (int) $proof['organization']->id;
        if ($resource instanceof Organization ? (int) $resource->getKey() !== $organizationId
            : OrganizationalUnit::query()->whereKey($resource->getKey())->where('organization_id', $organizationId)->sharedLock()->first(['id']) === null) {
            return false;
        }

        // A locking JOIN is a current read under InnoDB RR; EXISTS subqueries
        // do not inherit an outer locking clause. Direct permissions excluded.
        return DB::table('model_has_roles as mr')->join('roles as r', function ($join): void {
            $join->on('r.id', '=', 'mr.role_id')->on('r.organization_id', '=', 'mr.organization_id');
        })->join('role_has_permissions as rp', 'rp.role_id', '=', 'r.id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.organization_id', $organizationId)->where('mr.model_type', User::class)
            ->where('mr.model_id', $proof['actor']->id)->where('r.guard_name', 'web')
            ->where('p.guard_name', 'web')->where('p.name', $capability)->sharedLock()->first(['mr.role_id']) !== null;
    }
}
