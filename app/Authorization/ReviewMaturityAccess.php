<?php

namespace App\Authorization;

use App\Actions\Approaches\CreateApproach;
use App\Actions\Maturity\ManageStageReassessmentOperations;
use App\Actions\Reviews\ManageReviewMaturityAssignments;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use WeakMap;

/** Finite, sealed TEST_ONLY fixture. No config, header, session or route enables it. */
final class ReviewMaturityAccess
{
    public const SCENARIO = 'REVIEW_MATURITY_LOCAL_001';

    public const CAPABILITY = 'review_maturity.workspace.access';

    public const OPERATIONS = ['read_workflow', 'edit_content', 'review_content', 'configure_stage', 'answer_stage', 'review_stage'];

    public const REASSESSMENT_OPERATIONS = ['open_reassessment', 'save_reassessment', 'review_reassessment', 'history_reassessment'];

    private static ?WeakMap $requests = null;

    public static function runFixture(Request $request, array $proof, Closure $callback): mixed
    {
        $db = DB::connection();
        $j03 = ApproachAssignmentAccess::context();
        $documents = DocumentWorkspaceAccess::context();
        if (! app()->environment('testing') || request() !== $request || $j03 === null || $documents === null
            || ($proof['scenario'] ?? null) !== self::SCENARIO
            || ! preg_match('/\A[a-f0-9]{32}\z/', $proof['fixture_id'] ?? '')
            || ($proof['driver'] ?? null) !== $db->getDriverName() || ($proof['database'] ?? null) !== $db->getDatabaseName()
            || config('database.connections.'.$db->getName().'.url')) {
            throw new AuthorizationException;
        }
        foreach (['organization_id', 'approach_id', 'issuer_id', 'quality_id', 'author_id', 'reviewer_id'] as $field) {
            if (! is_int($proof[$field] ?? null) || $proof[$field] < 1) {
                throw new AuthorizationException;
            }
        }
        if (count(array_unique(array_intersect_key($proof, array_flip(['issuer_id', 'quality_id', 'author_id', 'reviewer_id'])))) !== 4
            || $j03['organization_id'] !== $proof['organization_id'] || $documents['organization_id'] !== $proof['organization_id']
            || $j03['issuer_id'] !== $proof['issuer_id'] || $documents['issuer_id'] !== $proof['issuer_id']
            || $j03['quality_id'] !== $proof['quality_id'] || $j03['contributor_id'] !== $proof['author_id']
            || $documents['editor_id'] !== $proof['author_id'] || $documents['reader_id'] !== $proof['reviewer_id']
            || $j03['fixture_id'] !== $proof['fixture_id'] || $documents['fixture_id'] !== $proof['fixture_id']) {
            throw new AuthorizationException;
        }
        if ($db->getDriverName() === 'mysql') {
            $actual = $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid, VERSION() AS version');
            if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
                || ! is_string($proof['server_uuid'] ?? null) || ! hash_equals($actual->uuid, $proof['server_uuid'])) {
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
        $old = config('authorization.capabilities', []);
        self::$requests[$request] = array_replace($proof, ['connection' => $db, 'pdo' => $db->getPdo()]);
        config(['authorization.capabilities' => $old + [self::CAPABILITY => Organization::class]]);
        try {
            return $callback();
        } finally {
            unset(self::$requests[$request]);
            config(['authorization.capabilities' => $old]);
        }
    }

    public static function context(): ?array
    {
        $proof = self::$requests[request()] ?? null;
        if ($proof === null || ! app()->environment('testing') || DB::connection() !== $proof['connection']
            || DB::connection()->getPdo() !== $proof['pdo'] || ApproachAssignmentAccess::context() === null
            || DocumentWorkspaceAccess::context() === null) {
            return null;
        }

        return $proof;
    }

    public static function timestamp(): string
    {
        return CarbonImmutable::now('UTC')->format('Y-m-d H:i:s');
    }

    public static function hasReassessmentScope(): bool
    {
        return (self::context()['reassessment_operation_scope'] ?? null) === 'CLOSURE_REASSESSMENT_LOCAL_001';
    }

    public function within(string $operation, string $publicId, ?int $stageId, Closure $callback, bool $audit = true): mixed
    {
        try {
            return app(DocumentWorkspaceAccess::class)->withSession(Auth::guard('institution')->user(), fn (User $actor) => app(CreateApproach::class)->withinContext($actor, function (int $organization, User $current) use ($operation, $publicId, $stageId, $callback): mixed {
                $proof = self::context() ?? throw new AuthorizationException;
                if (! hash_equals($current->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
                    throw new AuthorizationException;
                }
                $approach = Str::isUlid($publicId) ? Approach::where('organization_id', $organization)->whereKey($proof['approach_id'])
                    ->where('public_id', $publicId)->lockForUpdate()->first() : null;
                if ($approach === null) {
                    throw (new ModelNotFoundException)->setModel(Approach::class);
                }
                DB::table('maturity_workflow_stages')->where('organization_id', $organization)->where('fixture_id', $proof['fixture_id'])
                    ->where('approach_id', $approach->id)->orderBy('id')->lockForUpdate()->get();
                // Lock all finite grants in one order, including the two explicit notification recipients.
                $this->assignments()->orderBy('id')->lockForUpdate()->get();
                if (self::hasReassessmentScope()) {
                    $this->reassessmentAssignments()->orderBy('id')->lockForUpdate()->get();
                }
                $this->authorize($current, $operation, $approach, $stageId);
                $result = $callback($current, $approach);
                $this->authorize($current, $operation, $approach, $stageId);

                return $result;
            }));
        } catch (AuthorizationException|ModelNotFoundException $error) {
            if ($audit && self::context() !== null) {
                in_array($operation, self::REASSESSMENT_OPERATIONS, true)
                    ? app(ManageStageReassessmentOperations::class)->recordDenied($operation, $publicId, $stageId)
                    : app(ManageReviewMaturityAssignments::class)->recordDenied($operation, $publicId, $stageId);
            }
            throw $error;
        }
    }

    public function authorize(User $actor, string $operation, Approach $approach, ?int $stageId = null): void
    {
        Gate::forUser($actor)->authorize('workflowOperation', [ReviewMaturityReview::class, $operation, $approach, $stageId]);
    }

    public function assignments(): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return DB::table('review_maturity_assignments')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])
            ->where('scenario', self::SCENARIO)->where('classification', 'TEST_ONLY');
    }

    public function current(User $actor, string $operation, ?int $stageId): ?object
    {
        if (in_array($operation, self::REASSESSMENT_OPERATIONS, true) && (! self::hasReassessmentScope() || $stageId === null)) {
            return null;
        }
        $query = in_array($operation, self::REASSESSMENT_OPERATIONS, true) ? $this->reassessmentAssignments() : $this->assignments();

        return $query->where('grantee_id', $actor->id)->where('operation', $operation)->where('stage_id', $stageId)
            ->whereNull('revoked_at')->where('starts_at', '<=', self::timestamp())->where('expires_at', '>', self::timestamp())
            ->orderBy('id')->lockForUpdate()->first();
    }

    private function reassessmentAssignments(): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return DB::table('maturity_reassessment_operation_assignments')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id'])
            ->where('scenario', self::SCENARIO)->where('classification', 'TEST_ONLY');
    }

    public function allows(User $actor, string $operation, Approach $approach, ?int $stageId = null): bool
    {
        $proof = self::context();
        $reassessment = in_array($operation, self::REASSESSMENT_OPERATIONS, true);
        if ($proof === null || DB::transactionLevel() === 0 || (! in_array($operation, self::OPERATIONS, true) && ! ($reassessment && self::hasReassessmentScope()))
            || ! app(TenantContext::class)->verifiedFor($actor) || $actor->principal_type !== 'institution'
            || (int) $actor->organization_id !== $proof['organization_id'] || (int) $approach->organization_id !== $proof['organization_id']
            || (int) $approach->id !== $proof['approach_id'] || (int) Auth::guard('institution')->id() !== (int) $actor->id
            || request()->session()->get('identity.active_guard') !== 'institution'
            || ! hash_equals($actor->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
            return false;
        }
        $expected = match ($operation) {
            'read_workflow', 'history_reassessment' => [$proof['author_id'], $proof['reviewer_id']],
            'edit_content', 'answer_stage', 'open_reassessment', 'save_reassessment' => [$proof['author_id']],
            default => [$proof['reviewer_id']],
        };
        if (! in_array((int) $actor->id, $expected, true) || ((in_array($operation, ['configure_stage', 'answer_stage', 'review_stage'], true) || $reassessment) !== ($stageId !== null))
            || ($reassessment && $this->current($actor, 'read_workflow', null) === null)) {
            return false;
        }
        if ($stageId !== null && DB::table('maturity_workflow_stages')->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('approach_id', $approach->id)->where('id', $stageId)->lockForUpdate()->first() === null) {
            return false;
        }

        return $this->structural($actor) && $this->current($actor, $operation, $stageId) !== null;
    }

    /** Current joined read, independent of stale relationship caches / RR snapshots. Not global T17. */
    public function structural(User $actor): bool
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return DB::table('model_has_roles as mr')->join('roles as r', fn ($join) => $join->on('r.id', '=', 'mr.role_id')->on('r.organization_id', '=', 'mr.organization_id'))
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->join('organization_memberships as member', fn ($join) => $join->on('member.organization_id', '=', 'mr.organization_id')->on('member.user_id', '=', 'mr.model_id'))
            ->join('users as u', 'u.id', '=', 'mr.model_id')->where('mr.organization_id', $proof['organization_id'])
            ->where('u.organization_id', $proof['organization_id'])->where('u.principal_type', 'institution')
            ->where('mr.model_type', User::class)->where('mr.model_id', $actor->id)->where('r.guard_name', 'web')
            ->where('p.guard_name', 'web')->where('p.name', self::CAPABILITY)->sharedLock()->first(['mr.role_id']) !== null;
    }

    public function can(string $operation, string $publicId, ?int $stageId = null): bool
    {
        try {
            return $this->within($operation, $publicId, $stageId, fn () => true, audit: false);
        } catch (AuthorizationException|ModelNotFoundException) {
            return false;
        }
    }
}
