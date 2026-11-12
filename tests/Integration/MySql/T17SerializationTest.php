<?php

namespace Tests\Integration\MySql;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\Role;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;

/** Actual published contributor adapter; no synthetic Policy or capability fallback. */
class T17SerializationTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($this->assignmentFixtures as $f) {
                    DB::table('organizational_unit_changes')->where('organization_id', $f['organization']->id)->where('unit_id', $f['unit']->id)->delete();
                    DB::table('institution_password_reset_tokens')->whereIn('account_id', [$f['issuer']->id, $f['contributor']->id])->delete();
                }
                $this->cleanupAssignmentFixtures();
            }
        } finally {
            parent::tearDown();
        }
    }

    private function fixture(): array
    {
        $f = $this->assignmentFixture();
        config(['authorization.capabilities' => ['approaches.access' => Organization::class, 'organization.units.manage' => Organization::class]]);
        app(TenantContext::class)->run($f['issuer'], $f['organization']->id,
            fn () => app(RoleManagement::class)->syncPermissions($this->role($f), ['approaches.access', 'organization.units.manage']));
        $this->loginAssignmentActor($f['contributor'], $f['organization']);
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS value')->value);

        return $f;
    }

    private function role(array $f): Role
    {
        return Role::where('organization_id', $f['organization']->id)->where('name', 'j03-test-'.$f['proof']['fixture_id'])->firstOrFail();
    }

    private function child(array $f, string $mode, ?string $barrier = null): Process
    {
        $token = str_starts_with($mode, 'password') ? Password::broker('institution')->createToken($f['contributor']) : null;

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Security/T17/Worker.php')], base_path(), [
            'T17_TESTS_ENABLED' => '1', 'T17_SERVER_UUID' => $f['proof']['server_uuid'],
            'T17_WORKER_DATA' => json_encode(['proof' => $f['proof'], 'role' => $this->role($f)->id,
                'mode' => $mode, 'organization' => $f['organization']->public_id, 'token' => $token, 'barrier' => $barrier], JSON_THROW_ON_ERROR),
        ], timeout: 15);
    }

    private function finish(Process $child): void
    {
        if ($child->getStatus() === Process::STATUS_READY) {
            $child->start();
        }
        $child->wait();
        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        $lines = explode("\n", trim($child->getOutput()));
        $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('committed', $result['status']);
        $this->assertNull($result['error']);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $result['connection']);
        $this->assertSame('REPEATABLE-READ', $result['isolation']);
    }

    public static function revocations(): array
    {
        return [['role'], ['permission'], ['membership'], ['password']];
    }

    #[DataProvider('revocations')]
    public function test_committed_revocation_before_actual_adapter_lock_denies_write_and_audit(string $mode): void
    {
        $f = $this->fixture();
        $child = $this->child($f, $mode);
        $observed = false;
        $before = DB::table('organizational_unit_changes')->count();
        $name = $f['unit']->name;
        DB::connection()->beforeExecuting(function (string $sql) use (&$observed, $child): void {
            if (! $observed && str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update')) {
                $observed = true;
                $this->finish($child);
            }
        });
        $denied = false;
        try {
            app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'must not persist after revoke'));
        } catch (AuthorizationException) {
            $denied = true;
        } catch (HttpExceptionInterface $e) {
            $this->assertSame(403, $e->getStatusCode());
            $denied = true;
        }
        $this->assertTrue($observed, 'A real child commit must precede the actual organization lock.');
        $this->assertTrue($denied, 'The integrated adapter wrote after committed '.$mode.' revocation.');
        $this->assertSame($name, $f['unit']->fresh()->name);
        $this->assertSame($before, DB::table('organizational_unit_changes')->count());
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_new_permission_is_current_inside_an_existing_rr_read_view(): void
    {
        $f = $this->fixture();
        app(TenantContext::class)->run($f['issuer'], $f['organization']->id,
            fn () => app(RoleManagement::class)->syncPermissions($this->role($f), []));
        $child = $this->child($f, 'grant');
        DB::beginTransaction();
        try {
            $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $this->role($f)->id)->count());
            $this->finish($child);
            $this->assertSame(0, DB::table('role_has_permissions')->where('role_id', $this->role($f)->id)->count(), 'The RR snapshot must actually remain old.');
            app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'current grant observed'));
            $this->assertSame('current grant observed', $f['unit']->fresh()->name);
            $this->assertDatabaseCount('organizational_unit_changes', 1);
        } finally {
            DB::rollBack();
        }
    }

    public function test_writer_first_holds_organization_before_role_revocation_and_later_writes_deny(): void
    {
        $f = $this->fixture();
        $child = $this->child($f, 'role');
        config(['database.connections.t17_observer' => config('database.connections.mysql')]);
        DB::purge('t17_observer');
        $observer = DB::connection('t17_observer');
        $connection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        DB::beginTransaction();
        try {
            app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'writer linearized first'));
            $child->start();
            $waiting = false;
            $deadline = microtime(true) + 4;
            do {
                $waiting = (int) $observer->selectOne("SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                    JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID
                    JOIN performance_schema.threads t ON t.THREAD_ID=w.BLOCKING_THREAD_ID
                    WHERE t.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA='quality_platform_ci' AND l.OBJECT_NAME='organizations'", [$connection])->n > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $child->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Real MySQL lock graph must show the revoker waiting on Organization, before role/member suffix locks.');
            $this->assertTrue($child->isRunning());
            DB::commit();
            $this->finish($child);
            $denied = false;
            try {
                app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                    fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'not authorized'));
            } catch (AuthorizationException) {
                $denied = true;
            } catch (HttpExceptionInterface $e) {
                $this->assertSame(403, $e->getStatusCode());
                $denied = true;
            }
            $this->assertTrue($denied);
            $this->assertSame('writer linearized first', $f['unit']->fresh()->name);
            $this->assertDatabaseCount('organizational_unit_changes', 1);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($child->isRunning()) {
                $child->wait();
            }
            DB::purge('t17_observer');
        }
    }

    public function test_lock_timeout_aborts_the_whole_application_transaction_including_prior_writes(): void
    {
        $f = $this->fixture();
        $barrier = tempnam(sys_get_temp_dir(), 't17-');
        $child = $this->child($f, 'password-hold', $barrier);
        $wait = (int) DB::selectOne('SELECT @@innodb_lock_wait_timeout AS n')->n;
        $key = 't17-rollback-'.$f['proof']['fixture_id'];
        try {
            $child->start();
            $deadline = microtime(true) + 5;
            while (! str_contains($child->getOutput(), 'ORG_LOCKED') && $child->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertStringContainsString('ORG_LOCKED', $child->getOutput(), $child->getErrorOutput());
            DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
            DB::beginTransaction();
            DB::table('cache')->insert(['key' => $key, 'value' => 'earlier transaction write', 'expiration' => 1]);
            $failed = false;
            try {
                app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                    fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'must roll back'));
            } catch (QueryException|DeadlockException $e) {
                $cause = $e;
                while (! $cause instanceof QueryException && $cause->getPrevious() !== null) {
                    $cause = $cause->getPrevious();
                }
                $this->assertInstanceOf(QueryException::class, $cause);
                $this->assertSame(1205, (int) $cause->errorInfo[1]);
                $failed = true;
            }
            $this->assertTrue($failed);
            $this->assertSame(0, DB::transactionLevel(), 'A savepoint-only rollback must not leave prior application writes committable.');
            $this->assertDatabaseMissing('cache', ['key' => $key]);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
            $this->assertSame($f['unit']->name, $f['unit']->fresh()->name);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            file_put_contents($barrier, 'RESET');
            $this->finish($child);
            unlink($barrier);
            DB::statement('SET SESSION innodb_lock_wait_timeout = '.$wait);
        }
    }

    public function test_real_deadlock_preserves_original_1213_and_aborts_prior_application_writes(): void
    {
        $f = $this->fixture();
        $barrier = tempnam(sys_get_temp_dir(), 't17-');
        $child = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Support/t17-deadlock-worker.php')], base_path(), [
            'T17_DEADLOCK_DATA' => json_encode(['proof' => $f['proof'], 'barrier' => $barrier], JSON_THROW_ON_ERROR),
        ], timeout: 15);
        $started = false;
        $key = 't17-deadlock-parent-'.$f['proof']['fixture_id'];
        DB::connection()->beforeExecuting(function (string $sql) use (&$started, $child, $barrier): void {
            if (! $started && str_contains($sql, 'from `users`') && str_contains($sql, 'for update')) {
                $started = true;
                $child->start();
                $deadline = microtime(true) + 5;
                while (! str_contains($child->getOutput(), 'USER_LOCKED') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('USER_LOCKED', $child->getOutput(), $child->getErrorOutput());
                file_put_contents($barrier, 'CYCLE');
            }
        });
        DB::beginTransaction();
        try {
            DB::table('cache')->insert(['key' => $key, 'value' => 'must roll back on1213', 'expiration' => 1]);
            $failed = false;
            try {
                app(TenantContext::class)->run($f['contributor'], $f['organization']->id,
                    fn () => app(AuthorizedUnitOperations::class)->rename($f['contributor'], $f['organization']->public_id, $f['unit']->public_id, 'never commit on1213'));
            } catch (QueryException|DeadlockException $error) {
                $cause = $error;
                while (! $cause instanceof QueryException && $cause->getPrevious() !== null) {
                    $cause = $cause->getPrevious();
                }
                $this->assertInstanceOf(QueryException::class, $cause);
                $this->assertSame(1213, (int) $cause->errorInfo[1]);
                $failed = true;
            }
            $this->assertTrue($started);
            $this->assertTrue($failed, 'Real InnoDB deadlock must escape, without retry or fallback.');
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseMissing('cache', ['key' => $key]);
            $this->assertSame($f['unit']->name, $f['unit']->fresh()->name);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            file_put_contents($barrier, 'CYCLE');
            if ($child->isRunning()) {
                $child->wait();
            }
            $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
            $lines = explode("\n", trim($child->getOutput()));
            $result = json_decode(end($lines), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('committed', $result['status']);
            $this->assertSame('REPEATABLE-READ', $result['isolation']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $result['connection']);
            $keys = array_map(fn ($i) => 't17-deadlock-'.$f['proof']['fixture_id'].'-'.$i, range(0, 31));
            $this->assertSame(32, DB::table('cache')->whereIn('key', $keys)->delete());
            unlink($barrier);
        }
    }
}
