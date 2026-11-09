<?php

namespace App\Authorization;

use App\Models\Approach;
use App\Models\ApproachAssignment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use WeakMap;

/** Named TEST_ONLY boundary. Only trusted test entrypoints call runFixture(). */
final class ApproachAssignmentAccess
{
    public const SCENARIO = 'J03_LOCAL_APPROACH_001';

    public const OPERATIONS = ['quality', 'create', 'list', 'view', 'updateMetadata', 'editDraft', 'viewDraftHistory', 'viewAuditHistory', 'revokeContributor'];

    private static ?WeakMap $requests = null;

    public static function runFixture(Request $request, array $proof, Closure $operation): mixed
    {
        $db = DB::connection();
        if (! app()->environment('testing') || ($proof['scenario'] ?? null) !== self::SCENARIO
            || ! preg_match('/\A[a-f0-9]{32}\z/', $proof['fixture_id'] ?? '')
            || ($proof['driver'] ?? null) !== $db->getDriverName() || ($proof['database'] ?? null) !== $db->getDatabaseName()
            || config('database.connections.'.$db->getName().'.url')) {
            throw new AuthorizationException;
        }
        foreach (['organization_id', 'issuer_id', 'quality_id', 'contributor_id'] as $key) {
            if (! is_int($proof[$key] ?? null) || $proof[$key] < 1) {
                throw new AuthorizationException;
            }
        }
        if (count(array_unique([$proof['issuer_id'], $proof['quality_id'], $proof['contributor_id']])) !== 3) {
            throw new AuthorizationException;
        }
        if ($db->getDriverName() === 'mysql') {
            $actual = $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid, VERSION() AS version');
            if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
                || ! is_string($proof['server_uuid'] ?? null) || ! hash_equals($proof['server_uuid'], $actual->uuid)) {
                throw new AuthorizationException;
            }
        } elseif ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:'
            || collect($db->select('PRAGMA database_list'))->firstWhere('name', 'main')?->file !== '') {
            throw new AuthorizationException;
        }
        self::$requests ??= new WeakMap;
        if (isset(self::$requests[$request])) {
            throw new AuthorizationException;
        }
        $old = config('authorization.capabilities');
        self::$requests[$request] = array_replace($proof, ['connection' => $db, 'pdo' => $db->getPdo()]);
        config(['authorization.capabilities' => config('authorization.test_only_fixture_capabilities', [])]);
        try {
            return $operation();
        } finally {
            unset(self::$requests[$request]);
            config(['authorization.capabilities' => $old]);
        }
    }

    /** Null outside the exact request, including long-lived worker reuse. */
    public static function context(): ?array
    {
        $request = request();
        $proof = self::$requests[$request] ?? null;
        if ($proof === null || ! app()->environment('testing') || DB::connection() !== $proof['connection']
            || DB::connection()->getPdo() !== $proof['pdo']) {
            return null;
        }
        if ($proof['driver'] === 'mysql') {
            $actual = DB::selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid');
            if ($actual->name !== 'quality_platform_ci' || ! hash_equals($proof['server_uuid'], $actual->uuid)) {
                return null;
            }
        }

        return $proof;
    }

    public static function timestamp(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }

    public function query(User $actor, string $operation): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return ApproachAssignment::query()->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('scenario', self::SCENARIO)->where('classification', 'TEST_ONLY')
            ->where('grantee_id', $actor->id)->where('operation', $operation);
    }

    public function current(User $actor, string $operation, ?Approach $resource = null): ?ApproachAssignment
    {
        $query = $this->query($actor, $operation)->whereNull('revoked_at')
            ->where('starts_at', '<=', self::timestamp())->where('expires_at', '>', self::timestamp());
        if (in_array($operation, ['quality', 'create', 'list'], true)) {
            $query->where('scope_kind', 'institution')->whereNull('approach_id');
        } else {
            $query->where('scope_kind', 'approach')->whereNotNull('approach_id');
            if ($resource !== null) {
                $query->where('approach_id', $resource->id);
            }
        }

        return $query->orderBy('id')->lockForUpdate()->first();
    }

    public function allows(User $actor, string $operation, ?Approach $resource = null): bool
    {
        $proof = self::context();
        $tenant = app(TenantContext::class);
        if ($proof === null || ! in_array($operation, self::OPERATIONS, true) || ! $tenant->verifiedFor($actor)
            || $tenant->organizationId() !== $proof['organization_id'] || $actor->principal_type !== 'institution'
            || (int) $actor->organization_id !== $proof['organization_id']) {
            return false;
        }
        $organization = Organization::find($proof['organization_id']);
        if ($organization === null || ! app(TenantAuthorization::class)->allows($actor, 'approaches.access', $organization)
            || ! $this->currentStructuralGrant($actor, $proof['organization_id'])) {
            return false;
        }
        if ((int) $actor->id === $proof['quality_id'] && $this->current($actor, 'quality') === null) {
            return false;
        }
        if ($resource !== null && (int) $resource->organization_id !== $proof['organization_id']) {
            return false;
        }
        if (! in_array($operation, ['quality', 'create', 'list'], true) && $resource === null) {
            return false;
        }
        if ($this->current($actor, $operation, $resource) === null) {
            return false;
        }

        return $operation !== 'list' || $this->current($actor, 'create') !== null || $this->current($actor, 'view') !== null;
    }

    /** Additional TEST_ONLY current-read check; never replaces shared authorization. */
    private function currentStructuralGrant(User $actor, int $organizationId): bool
    {
        return DB::table('model_has_roles as member_roles')
            ->join('roles as scoped_roles', function ($join): void {
                $join->on('scoped_roles.id', '=', 'member_roles.role_id')->on('scoped_roles.organization_id', '=', 'member_roles.organization_id');
            })->join('role_has_permissions as role_permissions', 'role_permissions.role_id', '=', 'scoped_roles.id')
            ->join('permissions as capabilities', 'capabilities.id', '=', 'role_permissions.permission_id')
            ->where('member_roles.organization_id', $organizationId)->where('member_roles.model_type', User::class)
            ->where('member_roles.model_id', $actor->id)->where('scoped_roles.guard_name', 'web')
            ->where('capabilities.guard_name', 'web')->where('capabilities.name', 'approaches.access')
            ->sharedLock()->first(['member_roles.role_id']) !== null;
    }

    /** SQL current read; constrain rows/counts before pagination, never UI filtering. */
    public function constrain(Builder $query, User $actor): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return $query->join('approach_assignments as j03_view', function ($join) use ($proof, $actor): void {
            $join->on('j03_view.approach_id', '=', 'approaches.id')->on('j03_view.organization_id', '=', 'approaches.organization_id')
                ->where('j03_view.fixture_id', $proof['fixture_id'])->where('j03_view.scenario', self::SCENARIO)
                ->where('j03_view.classification', 'TEST_ONLY')->where('j03_view.grantee_id', $actor->id)
                ->where('j03_view.operation', 'view')->whereNull('j03_view.revoked_at')
                ->where('j03_view.starts_at', '<=', self::timestamp())->where('j03_view.expires_at', '>', self::timestamp());
        })->select('approaches.*')->sharedLock();
    }
}
