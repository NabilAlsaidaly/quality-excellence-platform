<?php

namespace Tests\Integration\MySql;

use App\Actions\Organizations\OrganizationManagement;
use App\Actions\Organizations\OrganizationProvisioning;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;

class OrganizationConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations;

    public static function renameResults(): array
    {
        return ['distinct second rename' => ['Second', 2], 'same committed name is no-op' => ['First', 1]];
    }

    #[DataProvider('renameResults')]
    public function test_waiting_rename_uses_committed_previous_name_instead_of_old_snapshot(string $name, int $events): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Initial']);
        $parentId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
        $worker = $this->worker(['actor_id' => $actor->id, 'public_id' => $organization->public_id, 'new_name' => $name]);
        DB::beginTransaction();
        try {
            $first = app(OrganizationProvisioning::class)->rename($actor, $organization->public_id, 'First');
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($worker->getOutput(), '"phase":"requesting_lock"')), $worker->getErrorOutput());
            $snapshot = collect($this->events($worker))->firstWhere('phase', 'snapshot');
            $this->assertNotNull($snapshot);
            $this->assertNotSame($parentId, $snapshot['connection_id']);
            $this->assertSame('REPEATABLE-READ', $snapshot['isolation']);
            $this->assertSame('Initial', $snapshot['previous_name']);
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
            $this->assertTrue($waiting, 'Competing rename must actually wait on the organization row.');
            $this->assertTrue($worker->isRunning());
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getOutput().$worker->getErrorOutput());
            $result = collect($this->events($worker))->firstWhere('phase', 'committed');
            $this->assertNotNull($result);
            $this->assertSame($name, $result['name']);
            if ($events === 1) {
                $this->assertSame($first->getRawOriginal('updated_at'), $result['updated_at']);
            }
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->assertSame($name, $organization->fresh()->name);
        $changes = DB::table('organization_changes')->where('organization_id', $organization->id)->orderBy('id')->get();
        $this->assertCount($events, $changes);
        $this->assertSame($events === 1 ? ['Initial'] : ['Initial', 'First'], $changes->pluck('previous_name')->all());
        $this->assertSame($events === 1 ? ['First'] : ['First', 'Second'], $changes->pluck('new_name')->all());
    }

    public function test_lock_timeout_is_atomic_and_retry_after_release_observes_current_name(): void
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create(['name' => 'Initial']);
        config(['database.connections.organization_timeout' => config('database.connections.mysql')]);
        $other = DB::connection('organization_timeout');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::beginTransaction();
        try {
            Organization::query()->whereKey($organization->id)->lockForUpdate()->first();
            try {
                DB::usingConnection('organization_timeout', fn () => app(OrganizationProvisioning::class)->rename($actor, $organization->public_id, 'Uncommitted'));
                $this->fail('Actual rename lock timeout required.');
            } catch (QueryException $error) {
                $this->assertSame(1205, $error->errorInfo[1]);
            }
            $this->assertSame(0, $other->transactionLevel());
            $this->assertSame('Initial', $organization->fresh()->name);
            $this->assertDatabaseCount('organization_changes', 0);
        } finally {
            DB::rollBack();
        }
        try {
            DB::usingConnection('organization_timeout', fn () => app(OrganizationProvisioning::class)->rename($actor, $organization->public_id, 'After release'));
            $this->assertSame('After release', $organization->fresh()->name);
            $change = DB::table('organization_changes')->sole();
            $this->assertSame('Initial', $change->previous_name);
            $this->assertSame('After release', $change->new_name);
        } finally {
            DB::purge('organization_timeout');
        }
    }

    public function test_renaming_one_root_does_not_lock_an_unrelated_root_with_the_same_actor(): void
    {
        $actor = User::factory()->create();
        $a = Organization::factory()->create(['name' => 'A']);
        $b = Organization::factory()->create(['name' => 'B']);
        config(['database.connections.organization_independent' => config('database.connections.mysql')]);
        $other = DB::connection('organization_independent');
        $other->statement('SET SESSION innodb_lock_wait_timeout = 1');
        DB::beginTransaction();
        try {
            app(OrganizationProvisioning::class)->rename($actor, $a->public_id, 'A pending');
            $result = DB::usingConnection('organization_independent', fn () => app(OrganizationProvisioning::class)->rename($actor, $b->public_id, 'B committed'));
            $this->assertSame('B committed', $result->name);
            $this->assertSame(0, $other->transactionLevel());
        } finally {
            DB::rollBack();
            DB::purge('organization_independent');
        }
        $this->assertSame('A', $a->fresh()->name);
        $this->assertSame('B committed', $b->fresh()->name);
        $change = DB::table('organization_changes')->sole();
        $this->assertSame($b->id, $change->organization_id);
        $this->assertSame('B', $change->previous_name);
    }

    public function test_current_metadata_membership_denies_a_revoked_row_visible_in_an_older_snapshot(): void
    {
        $membership = OrganizationMembership::factory()->create();
        config(['database.connections.organization_revoker' => config('database.connections.mysql')]);
        $other = DB::connection('organization_revoker');
        try {
            app(TenantContext::class)->run($membership->user, $membership->organization_id, function () use ($membership, $other): void {
                DB::beginTransaction();
                try {
                    $this->assertNotNull(DB::table('organization_memberships')->where('id', $membership->id)->first());
                    $this->assertSame(1, $other->table('organization_memberships')->where('id', $membership->id)->delete());
                    $this->assertTrue(app(TenantContext::class)->verifiedFor($membership->user));
                    try {
                        app(OrganizationManagement::class)->metadata($membership->user, $membership->organization->public_id);
                        $this->fail('Current membership must deny despite the old snapshot.');
                    } catch (AuthorizationException) {
                        $this->addToAssertionCount(1);
                    }
                    $this->assertDatabaseCount('organization_changes', 0);
                } finally {
                    DB::rollBack();
                }
            });
        } finally {
            DB::purge('organization_revoker');
        }
    }

    public function test_provisioning_rechecks_actor_existence_despite_an_old_snapshot(): void
    {
        $actor = User::factory()->create();
        config(['database.connections.organization_actor_remover' => config('database.connections.mysql')]);
        $other = DB::connection('organization_actor_remover');
        DB::beginTransaction();
        try {
            $this->assertTrue(User::query()->whereKey($actor->id)->exists());
            $this->assertSame(1, $other->table('users')->where('id', $actor->id)->delete());
            $this->assertTrue(User::query()->whereKey($actor->id)->exists());
            try {
                app(OrganizationProvisioning::class)->create($actor, 'Uncreated');
                $this->fail('Current actor existence is required.');
            } catch (AuthorizationException) {
                $this->addToAssertionCount(1);
            }
            $this->assertDatabaseCount('organizations', 0);
            $this->assertDatabaseCount('organization_changes', 0);
        } finally {
            DB::rollBack();
            DB::purge('organization_actor_remover');
        }
    }

    private function worker(array $payload): Process
    {
        $config = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Support/organization-worker.php')],
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
