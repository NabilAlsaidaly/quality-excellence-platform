<?php

namespace Tests\Integration\MySql;

use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\TenantContext;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;

class HierarchyConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations;

    public static function competingMoves(): array
    {
        return ['opposing sibling moves reject cycle' => [true], 'valid competing subtree move commits' => [false]];
    }

    #[DataProvider('competingMoves')]
    public function test_waiting_real_transaction_revalidates_committed_ancestry_despite_old_snapshot(bool $cycle): void
    {
        $membership = OrganizationMembership::factory()->create();
        $a = OrganizationalUnit::factory()->for($membership->organization)->create();
        $b = OrganizationalUnit::factory()->for($membership->organization)->create();
        $c = OrganizationalUnit::factory()->for($membership->organization)->create();
        $parentConnection = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS level')->level);
        $worker = $this->worker([
            'actor_id' => $membership->user_id, 'organization_id' => $membership->organization_id,
            'unit_public_id' => $b->public_id, 'parent_public_id' => $cycle ? $a->public_id : $c->public_id,
        ]);

        DB::beginTransaction();
        try {
            // First trusted action succeeds but its encompassing transaction
            // retains the common lock and uncommitted A -> B change.
            app(TenantContext::class)->run($membership->user, $membership->organization_id, fn () => app(OrganizationalUnitManagement::class)->move($membership->user, $a->public_id, $b->public_id));
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($worker->getOutput(), '"phase":"requesting_lock"')), $worker->getErrorOutput());
            $events = $this->events($worker);
            $snapshot = collect($events)->firstWhere('phase', 'snapshot');
            $this->assertNotNull($snapshot);
            $this->assertNotSame($parentConnection, $snapshot['connection_id']);
            $this->assertSame('REPEATABLE-READ', $snapshot['isolation']);
            $this->assertNull($snapshot['nodes'][$a->public_id]['parent_id']);
            $this->assertNull($snapshot['nodes'][$b->public_id]['parent_id']);

            // Observe an actual InnoDB wait, not merely a pre-query marker.
            $waiting = false;
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne(
                    "SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'organizations'",
                    [$snapshot['connection_id']],
                )->total > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Second real connection must wait for the common organization row.');
            $this->assertTrue($worker->isRunning());
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
            $this->assertContains($cycle ? 'rejected_cycle' : 'committed', array_column($this->events($worker), 'phase'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }

        $this->assertSame($b->id, $a->fresh()->parent_id);
        $this->assertSame($cycle ? null : $c->id, $b->fresh()->parent_id);
        $this->assertNull($c->fresh()->parent_id);
        $this->assertDatabaseCount('organizational_unit_changes', $cycle ? 1 : 2);
        app(TenantContext::class)->run($membership->user, $membership->organization_id, function () use ($membership, $a, $b, $c, $cycle): void {
            $tree = app(OrganizationalUnitManagement::class)->tree($membership->user);
            $this->assertSame([$a->public_id], $tree['nodes'][$b->public_id]['children']);
            $this->assertSame($cycle ? [$b->public_id, $c->public_id] : [$c->public_id], $tree['roots']);
        });
    }

    public function test_actual_lock_timeout_rolls_back_and_a_fresh_attempt_succeeds_after_release(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $a = OrganizationalUnit::factory()->for($membership->organization)->create();
        $b = OrganizationalUnit::factory()->for($membership->organization)->create();
        config(['database.connections.hierarchy_timeout' => config('database.connections.mysql')]);
        $competitor = DB::connection('hierarchy_timeout');
        $competitor->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $membership->organization_id)->lockForUpdate()->first();
            try {
                DB::usingConnection('hierarchy_timeout', fn () => app(TenantContext::class)->run(
                    $membership->user, $membership->organization_id,
                    fn () => app(OrganizationalUnitManagement::class)->move($membership->user, $a->public_id, $b->public_id)));
                $this->fail('Competing organization lock must actually time out.');
            } catch (QueryException $error) {
                $this->assertSame(1205, $error->errorInfo[1]);
            }
            $this->assertSame(0, $competitor->transactionLevel());
            $this->assertNull($a->fresh()->parent_id);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        } finally {
            DB::rollBack();
        }
        try {
            DB::usingConnection('hierarchy_timeout', fn () => app(TenantContext::class)->run(
                $membership->user, $membership->organization_id,
                fn () => app(OrganizationalUnitManagement::class)->move($membership->user, $a->public_id, $b->public_id)));
            $this->assertSame($b->id, $a->fresh()->parent_id);
            $this->assertDatabaseCount('organizational_unit_changes', 1);
        } finally {
            DB::purge('hierarchy_timeout');
        }
    }

    public function test_server_deadlock_error_after_update_rolls_back_data_and_history_without_retry(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $a = OrganizationalUnit::factory()->for($membership->organization)->create();
        $b = OrganizationalUnit::factory()->for($membership->organization)->create();
        $armed = true;
        $injections = 0;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed, &$injections): void {
            if ($armed && str_starts_with($sql, 'insert into `organizational_unit_changes`')) {
                $armed = false;
                $injections++;
                // Actual server error-path fault injection, not a claim of a
                // naturally occurring InnoDB deadlock between compliant writers.
                DB::statement("SIGNAL SQLSTATE '40001' SET MYSQL_ERRNO = 1213, MESSAGE_TEXT = 'Synthetic deadlock failure'");
            }
        });
        try {
            try {
                app(TenantContext::class)->run($membership->user, $membership->organization_id, fn () => app(OrganizationalUnitManagement::class)->move($membership->user, $a->public_id, $b->public_id));
                $this->fail('Injected server error must propagate.');
            } catch (QueryException $error) {
                $this->assertSame(1213, $error->errorInfo[1]);
            }
            $this->assertSame(1, $injections);
            $this->assertSame(0, DB::transactionLevel());
            $this->assertNull($a->fresh()->parent_id);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        } finally {
            $armed = false;
        }
    }

    public function test_revoked_membership_is_denied_even_when_existing_snapshot_still_sees_it(): void
    {
        $membership = OrganizationMembership::factory()->create();
        config(['database.connections.hierarchy_revoker' => config('database.connections.mysql')]);
        $revoker = DB::connection('hierarchy_revoker');
        try {
            app(TenantContext::class)->run($membership->user, $membership->organization_id, function () use ($membership, $revoker): void {
                DB::beginTransaction();
                try {
                    $this->assertNotNull(DB::table('organization_memberships')->where('id', $membership->id)->first());
                    $this->assertSame(1, $revoker->table('organization_memberships')->where('id', $membership->id)->delete());
                    // Ordinary REPEATABLE READ still sees the removed row.
                    $this->assertTrue(app(TenantContext::class)->verifiedFor($membership->user));
                    try {
                        app(OrganizationalUnitManagement::class)->create($membership->user, 'Uncreated fixture');
                        $this->fail('Current membership locking read must deny a revoked actor.');
                    } catch (AuthorizationException) {
                        $this->assertDatabaseCount('organizational_units', 0);
                        $this->assertDatabaseCount('organizational_unit_changes', 0);
                    }
                } finally {
                    DB::rollBack();
                }
            });
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            DB::purge('hierarchy_revoker');
        }
    }

    private function worker(array $payload): Process
    {
        $config = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Support/hierarchy-worker.php')],
            base_path(), [
                'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'MYSQL_TESTS_ENABLED' => '1',
                'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                'DB_DATABASE' => 'quality_platform_ci', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
            ]);
        $worker->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);

        return $worker;
    }

    private function events(Process $worker): array
    {
        return array_map(fn (string $line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", trim($worker->getOutput())))));
    }
}
