<?php

namespace Tests\Security\T17;

use App\Actions\Identity\BrowserAccountSession;
use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;

/** Deliberately RED security requirements, invoked explicitly; not an implementation fix. */
class RevocationRaceTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    private array $children = [];

    private array $barriers = [];

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $identity = $app['db']->connection()->selectOne('SELECT @@server_uuid AS uuid, VERSION() AS version');
        if (getenv('T17_TESTS_ENABLED') !== '1' || $identity->uuid !== getenv('T17_SERVER_UUID')
            || ! str_starts_with($identity->version, '8.4.')) {
            throw new \RuntimeException('T17 requires explicit verified owned MySQL before any migration hook.');
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('1', getenv('T17_TESTS_ENABLED'));
        $identity = DB::selectOne('SELECT @@server_uuid AS uuid, VERSION() AS version');
        $this->assertSame(getenv('T17_SERVER_UUID'), $identity->uuid);
        $this->assertStringStartsWith('8.4.', $identity->version);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::statement('SET SESSION innodb_lock_wait_timeout = 5');
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS value')->value);
        $this->assertSame('InnoDB', DB::selectOne("SELECT ENGINE AS engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='organizational_units'")->engine);
    }

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }
        foreach ($this->barriers as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        if ($this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($this->assignmentFixtures as $fixture) {
                if ($fixture['unit']->fresh()?->public_id !== $fixture['unit']->public_id) {
                    throw new \RuntimeException('Owned test unit identity changed.');
                }
                DB::table('organizational_unit_changes')->where('organization_id', $fixture['organization']->id)
                    ->where('unit_id', $fixture['unit']->id)->delete();
            }
            $this->cleanupAssignmentFixtures();
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            config(['authorization.capabilities' => ['approaches.access' => Organization::class, 'organization.units.manage' => Organization::class]]);
            app(TenantContext::class)->run($f['issuer'], $f['organization']->id, function () use ($f): void {
                app(RoleManagement::class)->syncPermissions($this->role($f), ['approaches.access', 'organization.units.manage']);
            });
        });

        return $f;
    }

    private function role(array $f): Role
    {
        return Role::where('organization_id', $f['organization']->id)->where('name', 'j03-test-'.$f['proof']['fixture_id'])->firstOrFail();
    }

    private function signalFile(): string
    {
        $file = tempnam(sys_get_temp_dir(), 't17-');
        $this->barriers[] = $file;

        return $file;
    }

    private function worker(array $f, string $mode, ?string $input = null): Process
    {
        $token = str_starts_with($mode, 'password') ? Password::broker('institution')->createToken($f['contributor']) : null;
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), __DIR__.'/Worker.php'], base_path(), [
            'T17_WORKER_DATA' => json_encode(['proof' => $f['proof'], 'role' => $this->role($f)->id,
                'mode' => $mode, 'organization' => $f['organization']->public_id, 'token' => $token, 'barrier' => $input], JSON_THROW_ON_ERROR),
        ], null, 20);
        $this->children[] = $process;

        return $process;
    }

    private function finish(Process $child): array
    {
        $child->wait();
        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        $lines = explode("\n", trim($child->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $result['connection']);
        $this->assertSame('REPEATABLE-READ', $result['isolation']);

        return $result;
    }

    private function barrier(Process $child, string $message): void
    {
        $deadline = microtime(true) + 8;
        while (! str_contains($child->getOutput(), $message) && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString($message, $child->getOutput(), $child->getErrorOutput());
    }

    private function trace(string $case, array $data): void
    {
        fwrite(STDOUT, 'T17 '.json_encode(['case' => $case, 'parent_connection' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id] + $data, JSON_THROW_ON_ERROR).PHP_EOL);
    }

    private function unitScope(array $f, callable $operation): mixed
    {
        config(['authorization.capabilities' => ['organization.units.manage' => Organization::class, 'approaches.access' => Organization::class]]);

        return app(TenantContext::class)->run($f['contributor'], $f['organization']->id, $operation);
    }

    public static function gateRaces(): array
    {
        return [['role'], ['permission'], ['password'], ['membership']];
    }

    #[DataProvider('gateRaces')]
    public function test_revoke_committed_after_gate_must_prevent_unit_write_and_success_audit(string $mode): void
    {
        $f = $this->fixture();
        $this->loginAssignmentActor($f['contributor'], $f['organization']);
        $this->unitScope($f, function () use ($f, $mode): void {
            Gate::forUser($f['contributor'])->authorize('tenant-capability', ['organization.units.manage', $f['organization']]);
            $this->assertSame(0, DB::transactionLevel(), 'Reported gap: Gate precedes domain write transaction.');
            $child = $this->worker($f, $mode);
            $child->start();
            $result = $this->finish($child);
            $this->assertSame('committed', $result['status']);
            $denied = false;
            try {
                app(OrganizationalUnitManagement::class)->rename($f['contributor'], $f['unit']->public_id, 'T17 must not commit');
            } catch (AuthorizationException) {
                $denied = true;
            }
            $writes = DB::table('organizational_unit_changes')->where('unit_id', $f['unit']->id)->count();
            $this->trace('gate-'.$mode, ['gate_before_transaction' => true, 'child' => $result, 'denied' => $denied, 'success_audits' => $writes, 'unit_changed' => $f['unit']->fresh()->name === 'T17 must not commit']);
            $this->assertTrue($denied, 'T17 SECURITY GAP: accepted mutation after committed '.$mode.' change. This is the existing domain boundary, not unpublished coworker code.');
            $this->assertSame(0, $writes);
            $this->assertSame('وحدة اختبار المنهجيات', $f['unit']->fresh()->name);
        });
    }

    public static function structuralChanges(): array
    {
        return [['role'], ['permission'], ['grant']];
    }

    #[DataProvider('structuralChanges')]
    public function test_structural_decision_must_use_current_grants_in_an_old_rr_snapshot(string $mode): void
    {
        $f = $this->fixture();
        if ($mode === 'grant') {
            $this->unitScope($f, fn () => app(RoleManagement::class)->syncPermissions($this->role($f), []));
        }
        $this->unitScope($f, function () use ($f, $mode): void {
            DB::beginTransaction();
            try {
                $auth = app(TenantAuthorization::class);
                $this->assertSame($mode !== 'grant', $auth->allows($f['contributor'], 'organization.units.manage', $f['organization']));
                $child = $this->worker($f, $mode);
                $child->start();
                $this->assertSame('committed', $this->finish($child)['status']);
                $snapshot = $auth->allows($f['contributor'], 'organization.units.manage', $f['organization']);
                $sql = 'SELECT mr.role_id FROM model_has_roles mr JOIN roles r ON r.id=mr.role_id AND r.organization_id=mr.organization_id JOIN role_has_permissions rp ON rp.role_id=r.id JOIN permissions p ON p.id=rp.permission_id WHERE mr.organization_id=? AND mr.model_type=? AND mr.model_id=? AND r.guard_name=? AND p.guard_name=? AND p.name=? FOR SHARE';
                $bindings = [$f['organization']->id, User::class, $f['contributor']->id, 'web', 'web', 'organization.units.manage'];
                $current = DB::select($sql, $bindings) !== [];
                $this->assertSame($mode === 'grant', $current);
                $outer = (bool) DB::selectOne('SELECT EXISTS('.substr($sql, 0, -strlen(' FOR SHARE')).') AS allowed FOR UPDATE', $bindings)->allowed;
                $this->assertSame($snapshot, $outer, 'Outer locking clause did not make the nested grant query current.');
                $plan = DB::select('EXPLAIN FORMAT=JSON '.$sql, $bindings)[0];
                $this->trace('rr-'.$mode, ['snapshot' => $snapshot, 'current_join' => $current, 'outer_exists' => $outer, 'plan' => json_decode($plan->EXPLAIN, true)]);
                $this->assertSame($current, $snapshot, 'T17 current-decision requirement: grant case is stale fail-closed denial; revoke cases are stale allow.');
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_existing_approach_rejects_password_reset_committed_while_waiting_for_organization(): void
    {
        $f = $this->fixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $input = $this->signalFile();
            $child = $this->worker($f, 'password-hold', $input);
            $child->start();
            $this->barrier($child, 'ORG_LOCKED');
            $release = true;
            DB::connection()->beforeExecuting(function ($sql) use (&$release, $input): void {
                if ($release && str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update')) {
                    $release = false;
                    file_put_contents($input, 'RESET', LOCK_EX);
                }
            });
            $denied = false;
            try {
                app(ApproachAccess::class)->saveDraft($id, 'must not survive password reset', 'none');
            } catch (AuthorizationException) {
                $denied = true;
            }
            $this->assertSame('committed', $this->finish($child)['status']);
            $this->assertTrue($denied);
            $this->assertDatabaseCount('approach_content_revisions', 0);
            $this->trace('j03-password-wait', ['denied' => $denied, 'drafts' => 0, 'reset_service' => 'InstitutionRecovery::reset', 'outer_worker_org_barrier' => true]);
        });
    }

    public function test_current_session_end_clears_context_and_prevents_another_unit_call(): void
    {
        $f = $this->fixture();
        $this->loginAssignmentActor($f['contributor'], $f['organization']);
        $this->unitScope($f, function () use ($f): void {
            app(BrowserAccountSession::class)->end(request());
            $this->expectException(AuthorizationException::class);
            app(OrganizationalUnitManagement::class)->rename($f['contributor'], $f['unit']->public_id, 'must deny');
        });
    }

    public function test_unrevoked_unit_operation_still_persists_one_success_audit(): void
    {
        $f = $this->fixture();
        $this->unitScope($f, function () use ($f): void {
            Gate::forUser($f['contributor'])->authorize('tenant-capability', ['organization.units.manage', $f['organization']]);
            app(OrganizationalUnitManagement::class)->rename($f['contributor'], $f['unit']->public_id, 'allowed control');
            $this->assertSame('allowed control', $f['unit']->fresh()->name);
            $this->assertSame(1, DB::table('organizational_unit_changes')->where('unit_id', $f['unit']->id)->count());
        });
    }

    public function test_default_capability_and_foreign_tenant_remain_denied(): void
    {
        $f = $this->fixture();
        $foreign = $this->fixture();
        $this->assertSame([], config('authorization.capabilities'));
        app(TenantContext::class)->run($f['contributor'], $f['organization']->id, function () use ($f): void {
            $this->assertFalse(Gate::forUser($f['contributor'])->allows('tenant-capability', ['organization.units.manage', $f['organization']]));
        });
        $this->unitScope($f, function () use ($f, $foreign): void {
            $this->assertFalse(Gate::forUser($f['contributor'])->allows('tenant-capability', ['organization.units.manage', $foreign['organization']]));
            try {
                app(OrganizationalUnitManagement::class)->rename($f['contributor'], $foreign['unit']->public_id, 'foreign');
                $this->fail('Foreign target accepted.');
            } catch (ModelNotFoundException) {
                $this->assertSame('وحدة اختبار المنهجيات', $foreign['unit']->fresh()->name);
                $this->assertSame(0, DB::table('organizational_unit_changes')->count());
            }
        });
    }

    public function test_opposite_membership_role_order_reproduces_a_real_deadlock_without_partial_success(): void
    {
        $f = $this->fixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $input = $this->signalFile();
            $child = $this->worker($f, 'deadlock', $input);
            $start = true;
            DB::listen(function ($query) use (&$start, $child, $input): void {
                if ($start && str_contains($query->sql, 'from `organization_memberships`') && str_contains($query->sql, 'for update')) {
                    $start = false;
                    $child->start();
                    $this->barrier($child, 'ROLE_LOCKED');
                    file_put_contents($input, 'GO', LOCK_EX);
                }
            });
            $parentError = null;
            try {
                app(ApproachAccess::class)->saveDraft($id, 'ordered commit or rollback', 'none');
            } catch (QueryException $exception) {
                $parentError = (int) $exception->errorInfo[1];
            }
            $result = $this->finish($child);
            $this->assertContains(1213, [$parentError, $result['error']], 'An actual detected deadlock, not a sleep/timeout assumption.');
            $drafts = DB::table('approach_content_revisions')->count();
            $revokes = DB::table('authorization_changes')->where('event', 'role.revoked')->count();
            $this->assertSame($parentError === null ? 1 : 0, $drafts);
            $this->assertSame($result['status'] === 'committed' ? 1 : 0, $revokes);
            $this->trace('actual-deadlock', ['parent_error' => $parentError, 'child' => $result, 'drafts' => $drafts, 'revocation_audits' => $revokes]);
        });
    }
}
