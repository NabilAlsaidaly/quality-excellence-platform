<?php

namespace App\Authorization;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Models\Document;
use App\Models\DocumentWorkspaceAssignment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use WeakMap;

/** A named internal testing boundary; no route, provider or configuration enables it. */
final class DocumentWorkspaceAccess
{
    public const SCENARIO = 'DOCUMENT_LOCAL_001';

    public const CAPABILITY = 'documents.workspace.access';

    public const OPERATIONS = ['create', 'list', 'view', 'metadata', 'append', 'history'];

    private static ?WeakMap $requests = null;

    public function __construct(private CreateDocumentWithVersion $boundary) {}

    public static function runFixture(Request $request, array $proof, Closure $operation): mixed
    {
        $db = DB::connection();
        if (! app()->environment('testing') || ($proof['scenario'] ?? null) !== self::SCENARIO
            || ! preg_match('/\A[a-f0-9]{32}\z/', $proof['fixture_id'] ?? '')
            || ($proof['driver'] ?? null) !== $db->getDriverName() || ($proof['database'] ?? null) !== $db->getDatabaseName()
            || config('database.connections.'.$db->getName().'.url')) {
            throw new AuthorizationException;
        }
        foreach (['organization_id', 'issuer_id', 'editor_id', 'reader_id'] as $field) {
            if (! is_int($proof[$field] ?? null) || $proof[$field] < 1) {
                throw new AuthorizationException;
            }
        }
        if (count(array_unique([$proof['issuer_id'], $proof['editor_id'], $proof['reader_id']])) !== 3) {
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
        $old = config('authorization.capabilities', []);
        self::$requests[$request] = $proof + ['connection' => $db, 'pdo' => $db->getPdo()];
        config(['authorization.capabilities' => $old + [self::CAPABILITY => Organization::class]]);
        try {
            return $operation();
        } finally {
            unset(self::$requests[$request]);
            config(['authorization.capabilities' => $old]);
        }
    }

    public static function context(): ?array
    {
        $proof = self::$requests[request()] ?? null;
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

    /** Real institutional guard/provider/session, independent of Approach admission. */
    public function withSession(?User $actor, Closure $operation): mixed
    {
        $proof = self::context() ?? throw new AuthorizationException;
        $guard = Auth::guard('institution');
        $cached = $guard->user();
        $current = $cached ? $guard->getProvider()->retrieveById($cached->getAuthIdentifier()) : null;
        $session = request()->hasSession() ? request()->session() : null;
        $organization = $session?->get('institution.organization');
        if ($actor === null || $current === null || $current->principal_type !== 'institution'
            || (int) $actor->id !== (int) $current->id || (int) $current->organization_id !== $proof['organization_id']
            || ! is_string($organization) || ! Str::isUlid($organization)
            || $session->get('identity.active_guard') !== 'institution'
            || ! hash_equals($current->getAuthPassword(), (string) $session->get('institution.password_hash'))
            || ! hash_equals($current->getAuthPassword(), $actor->getAuthPassword())) {
            throw new AuthorizationException;
        }
        $guard->setUser($current);

        return app(TenantContext::class)->run($current, $organization, function () use ($current, $proof, $operation): mixed {
            if (app(TenantContext::class)->organizationId() !== $proof['organization_id']) {
                throw new AuthorizationException;
            }

            return $operation($current);
        });
    }

    /** Organization -> actor -> membership -> document -> current grants -> result. */
    public function within(?User $actor, string $operation, ?string $publicId, Closure $callback, bool $audit = true): mixed
    {
        try {
            return $this->withSession($actor, fn (User $current) => $this->boundary->withinContext($current,
                function (Organization $organization, User $locked) use ($operation, $publicId, $callback): mixed {
                    $document = null;
                    if ($publicId !== null) {
                        $document = Str::isUlid($publicId) ? Document::query()->where('organization_id', $organization->id)
                            ->where('public_id', $publicId)->lockForUpdate()->first() : null;
                        if ($document === null) {
                            throw (new ModelNotFoundException)->setModel(Document::class);
                        }
                    }
                    $this->authorize($locked, $operation, $document);
                    $result = $callback($locked, $document);
                    $this->authorize($locked, $operation, $document);

                    return $result;
                }));
        } catch (AuthorizationException|ModelNotFoundException $error) {
            if ($audit) {
                try {
                    app(ManageDocumentWorkspaceAssignments::class)->recordDenied($actor, $operation, $publicId);
                } catch (AuthorizationException) {
                    // No event may be attributed to an unverifiable actor/context.
                }
            }
            throw $error;
        }
    }

    /** Side-effect-free; also used after file/SQL waits before commit. */
    public function authorize(User $actor, string $operation, ?Document $document = null): void
    {
        Gate::forUser($actor)->authorize('workspaceOperation', [Document::class, $operation, $document]);
    }

    public function query(User $actor, string $operation): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;

        return DocumentWorkspaceAssignment::query()->where('organization_id', $proof['organization_id'])
            ->where('fixture_id', $proof['fixture_id'])->where('scenario', self::SCENARIO)->where('classification', 'TEST_ONLY')
            ->where('grantee_id', $actor->id)->where('operation', $operation);
    }

    public function current(User $actor, string $operation, ?Document $document = null): ?DocumentWorkspaceAssignment
    {
        $now = self::timestamp();
        $query = $this->query($actor, $operation)->whereNull('revoked_at')->where('starts_at', '<=', $now)->where('expires_at', '>', $now);
        if (in_array($operation, ['create', 'list'], true)) {
            $query->where('scope_kind', 'institution')->whereNull('document_id');
        } else {
            $query->where('scope_kind', 'document')->where('document_id', $document?->id);
        }

        return $query->orderBy('id')->lockForUpdate()->first();
    }

    public function allows(User $actor, string $operation, ?Document $document = null): bool
    {
        $proof = self::context();
        $context = app(TenantContext::class);
        if ($proof === null || DB::transactionLevel() === 0 || ! in_array($operation, self::OPERATIONS, true)
            || ! $context->verifiedFor($actor) || $context->organizationId() !== $proof['organization_id']
            || $actor->principal_type !== 'institution' || (int) $actor->organization_id !== $proof['organization_id']
            || ! in_array((int) $actor->id, [$proof['editor_id'], $proof['reader_id']], true)
            || ! request()->hasSession() || (int) Auth::guard('institution')->id() !== (int) $actor->id
            || request()->session()->get('identity.active_guard') !== 'institution'
            || ! hash_equals($actor->getAuthPassword(), (string) request()->session()->get('institution.password_hash'))) {
            return false;
        }
        if (! in_array($operation, ['create', 'list'], true)
            && ($document === null || (int) $document->organization_id !== $proof['organization_id'])) {
            return false;
        }
        if ((int) $actor->id === $proof['reader_id'] && ! in_array($operation, ['list', 'view', 'history'], true)) {
            return false;
        }
        $organization = Organization::find($proof['organization_id']);
        if ($organization === null || ! app(TenantAuthorization::class)->allows($actor, self::CAPABILITY, $organization)
            || ! $this->currentStructuralGrant($actor, $proof['organization_id'])) {
            return false;
        }

        return $this->current($actor, $operation, $document) !== null;
    }

    private function currentStructuralGrant(User $actor, int $organization): bool
    {
        return DB::table('model_has_roles as mr')->join('roles as r', fn ($join) => $join
            ->on('r.id', '=', 'mr.role_id')->on('r.organization_id', '=', 'mr.organization_id'))
            ->join('role_has_permissions as rp', 'rp.role_id', '=', 'r.id')->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('mr.organization_id', $organization)->where('mr.model_type', User::class)->where('mr.model_id', $actor->id)
            ->where('r.guard_name', 'web')->where('p.guard_name', 'web')->where('p.name', self::CAPABILITY)
            ->sharedLock()->first(['mr.role_id']) !== null;
    }

    /** Current joined authorization before search/count/sort/page; no PHP filtering. */
    public function constrain(Builder $query, User $actor): Builder
    {
        $proof = self::context() ?? throw new AuthorizationException;
        $now = self::timestamp();

        return $query->join('document_workspace_assignments as document_view', function ($join) use ($proof, $actor, $now): void {
            $join->on('document_view.document_id', '=', 'documents.id')->on('document_view.organization_id', '=', 'documents.organization_id')
                ->where('document_view.fixture_id', $proof['fixture_id'])->where('document_view.scenario', self::SCENARIO)
                ->where('document_view.classification', 'TEST_ONLY')->where('document_view.scope_kind', 'document')
                ->where('document_view.grantee_id', $actor->id)->where('document_view.operation', 'view')->whereNull('document_view.revoked_at')
                ->where('document_view.starts_at', '<=', $now)->where('document_view.expires_at', '>', $now);
        })->sharedLock();
    }
}
