<?php

namespace Tests\Integration\MySql;

use App\Actions\Approaches\ChangeApproachOwner;
use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ListApproaches;
use App\Actions\Approaches\SaveApproachContentRevision;
use App\Actions\Approaches\UpdateApproach;
use App\Authorization\TenantContext;
use App\Models\Approach;
use App\Models\ApproachChange;
use App\Models\ApproachContentRevision;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantIsolationSchemaContract;

class ApproachSchemaIsolationTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    private array $ownedApproaches = [];

    /** Keep the original feature DDL target independent of later document migrations. */
    private function rollbackApproachMigrations(int $step): PendingCommand
    {
        $this->rollbackEmptyAssignmentMigrations();
        $paths = [
            'database/migrations/2026_11_07_000001_create_approaches_table.php',
            'database/migrations/2026_11_07_000002_create_approach_content_revisions_table.php',
            'database/migrations/2026_11_08_000001_create_approach_changes_table.php',
        ];

        return $this->artisan('migrate:rollback', ['--path' => array_slice($paths, -$step),
            '--step' => DB::table('migrations')->count(), '--force' => true]);
    }

    protected function tearDown(): void
    {
        if ($this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            // Only this guarded test's synthetic fixtures, before safe empty DDL rollback.
            if (DB::connection()->getDatabaseName() !== 'quality_platform_ci' || getenv('MYSQL_TESTS_ENABLED') !== '1') {
                throw new RuntimeException('Refuse fixture cleanup outside guarded disposable MySQL.');
            }
            if (Schema::hasTable('approach_content_revisions')) {
                DB::table('approach_content_revisions')->whereIn('approach_id', $this->ownedApproaches)->delete();
            }
            if (Schema::hasTable('approach_changes')) {
                DB::table('approach_changes')->whereIn('approach_id', $this->ownedApproaches)->delete();
            }
            if (Schema::hasTable('approaches')) {
                DB::table('approaches')->whereIn('id', $this->ownedApproaches)->delete();
            }
        }
        parent::tearDown();
    }

    private function fixture(): array
    {
        $member = OrganizationMembership::factory()->create();
        $unit = OrganizationalUnit::factory()->for($member->organization)->create();

        return [$member, $unit];
    }

    private function create(OrganizationMembership $member, OrganizationalUnit $unit): Approach
    {
        $approach = $this->trusted($member, fn () => app(CreateApproach::class)->execute($member->user,
            ['name' => 'Synthetic approach', 'description' => 'description', 'purpose' => 'purpose', 'scope' => 'scope',
                'owning_unit_public_id' => $unit->public_id]));
        $this->ownedApproaches[] = $approach->id;

        return $approach;
    }

    private function trusted(OrganizationMembership $member, callable $operation): mixed
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id, $operation(...));
    }

    private function sqlFailure(callable $operation, int $code): void
    {
        try {
            $operation();
            $this->fail('Expected actual MySQL constraint rejection.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1], $error->getMessage());
        }
    }

    public function test_actual_schema_and_journal_match_explicit_reviewed_tenant_contract(): void
    {
        $this->assertSame('quality_platform_ci', DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertSame(26, count(glob(database_path('migrations/*.php'))));
        $this->assertSame(26, DB::table('migrations')->count());
        $schema = TenantIsolationSchemaContract::read(DB::connection());
        $this->assertSame([], TenantIsolationSchemaContract::violations($schema));
        $this->assertSame('organization_id,created_at,id', $schema['approaches']['indexes']['approaches_organization_created_idx']['columns']);
        $this->assertSame('organization_id,name,id', $schema['approaches']['indexes']['approaches_organization_name_idx']['columns']);
        foreach (['approaches', 'approach_content_revisions', 'approach_changes'] as $table) {
            $this->assertSame('direct', TenantIsolationSchemaContract::TABLES[$table]);
            $this->assertSame('InnoDB', $schema[$table]['engine']);
            $this->assertSame(['type' => 'bigint unsigned', 'nullable' => false], $schema[$table]['columns']['id']);
            $this->assertSame(['type' => 'char(26)', 'nullable' => false], $schema[$table]['columns']['public_id']);
            foreach ($schema[$table]['foreign_keys'] as $key) {
                $changed = $schema;
                $changed[$table]['foreign_keys'] = array_values(array_filter($schema[$table]['foreign_keys'], fn ($candidate) => $candidate !== $key));
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' removed FK');
                $changed = $schema;
                $name = array_search($key, $changed[$table]['foreign_keys'], true);
                $changed[$table]['foreign_keys'][$name]['delete'] = 'CASCADE';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' cascade');
            }
            foreach ($schema[$table]['indexes'] as $name => $key) {
                if (! $key['unique']) {
                    continue;
                }
                $changed = $schema;
                unset($changed[$table]['indexes'][$name]);
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' removed UNIQUE');
            }
            $changed = $schema;
            $changed[$table]['columns']['organization_id']['nullable'] = true;
            $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
        }
        $changed = $schema;
        $changed['approach_content_revisions']['checks']['approach_revisions_version_check']['enforced'] = false;
        $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
        $this->assertSame('organization_id,approach_id,id', $schema['approach_changes']['indexes']['approach_changes_history_idx']['columns']);
        foreach (['approach_changes_event_check', 'approach_changes_state_check', 'approach_changes_owner_check'] as $name) {
            foreach (['enforced' => false, 'clause' => '1'] as $key => $value) {
                $changed = $schema;
                $changed['approach_changes']['checks'][$name][$key] = $value;
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
        }
    }

    public function test_raw_insert_update_owner_and_revision_references_cannot_cross_organizations(): void
    {
        [$a, $unitA] = $this->fixture();
        [$b, $unitB] = $this->fixture();
        $approach = $this->create($a, $unitA);
        $row = $approach->getAttributes();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        $row['owning_unit_id'] = $unitB->id;
        $this->sqlFailure(fn () => DB::table('approaches')->insert($row), 1452);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->update(['owning_unit_id' => $unitB->id]), 1452);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->update(['organization_id' => $b->organization_id]), 1452);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->update(['owning_unit_id' => null]), 1048);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->update(['owning_unit_id' => 999999999]), 1452);
        $revision = $this->trusted($a, fn () => app(SaveApproachContentRevision::class)->execute($a->user, $approach->public_id, 'نص'));
        $copy = $revision->getAttributes();
        unset($copy['id']);
        $copy['public_id'] = (string) Str::ulid();
        $copy['organization_id'] = $b->organization_id;
        $copy['version_number'] = 2;
        $this->sqlFailure(fn () => DB::table('approach_content_revisions')->insert($copy), 1452);
        $this->sqlFailure(fn () => DB::table('approach_content_revisions')->where('id', $revision->id)->update(['organization_id' => $b->organization_id]), 1452);
        $this->assertSame($unitA->id, $approach->fresh()->owning_unit_id);
        $this->assertDatabaseCount('approaches', 1);
        $this->assertDatabaseCount('approach_content_revisions', 1);
    }

    public function test_unique_versions_ulids_positive_numbers_and_restrict_history_are_enforced(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'تاريخ'));
        $copy = $revision->getAttributes();
        unset($copy['id']);
        $copy['public_id'] = (string) Str::ulid();
        $this->sqlFailure(fn () => DB::table('approach_content_revisions')->insert($copy), 1062);
        $copy['version_number'] = 2;
        $copy['public_id'] = $revision->public_id;
        $this->sqlFailure(fn () => DB::table('approach_content_revisions')->insert($copy), 1062);
        $this->sqlFailure(fn () => DB::table('approach_content_revisions')->where('id', $revision->id)->update(['version_number' => 0]), 3819);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->delete(), 1451);
        $this->sqlFailure(fn () => DB::table('organizational_units')->where('id', $unit->id)->delete(), 1451);
        // Remove only this synthetic structural membership, so the creator FK is isolated.
        $member->delete();
        $this->sqlFailure(fn () => DB::table('users')->where('id', $member->user_id)->delete(), 1451);
        $author = User::factory()->create();
        $copy = $revision->getAttributes();
        unset($copy['id']);
        $copy['public_id'] = (string) Str::ulid();
        $copy['version_number'] = 2;
        $copy['actor_id'] = $author->id;
        DB::table('approach_content_revisions')->insert($copy);
        $this->sqlFailure(fn () => DB::table('users')->where('id', $author->id)->delete(), 1451);
        $this->assertSame('تاريخ', $revision->fresh()->body);
    }

    public function test_empty_additive_rollback_repeat_and_atomic_fk_failure_recover_without_partial_table(): void
    {
        $this->rollbackApproachMigrations(3)->assertExitCode(0);
        $this->assertSame(21, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasTable('approaches'));
        $this->assertFalse(Schema::hasTable('approach_content_revisions'));
        DB::statement('RENAME TABLE organizational_units TO approach_units_fixture');
        try {
            $migration = require database_path('migrations/2026_11_07_000001_create_approaches_table.php');
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('approaches'));
            $this->assertSame(21, DB::table('migrations')->count());
        } finally {
            DB::statement('RENAME TABLE approach_units_fixture TO organizational_units');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->assertSame(26, DB::table('migrations')->count());
    }

    public function test_revision_fk_ddl_failure_preserves_parent_and_retries_with_no_orphan_table(): void
    {
        $this->rollbackApproachMigrations(2)->assertExitCode(0);
        DB::statement('RENAME TABLE approaches TO approach_parent_fixture');
        try {
            $migration = require database_path('migrations/2026_11_07_000002_create_approach_content_revisions_table.php');
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('approach_content_revisions'));
            $this->assertSame(22, DB::table('migrations')->count());
        } finally {
            DB::statement('RENAME TABLE approach_parent_fixture TO approaches');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->assertSame(26, DB::table('migrations')->count());
    }

    public function test_populated_rollback_refuses_before_first_drop_and_existing_table_is_not_adopted(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'preserved'));
        $migration = require database_path('migrations/2026_11_07_000001_create_approaches_table.php');
        $this->sqlFailure(fn () => $migration->up(), 1050);
        foreach ([fn () => Artisan::call('migrate:rollback', ['--path' => ['database/migrations/2026_11_08_000001_create_approach_changes_table.php'], '--step' => DB::table('migrations')->count(), '--force' => true]),
            fn () => $migration->down()] as $operation) {
            try {
                $operation();
                $this->fail('Populated rollback must preserve both tables.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
            }
            $this->assertTrue(Schema::hasTable('approach_content_revisions'));
            $this->assertSame(26, DB::table('migrations')->count());
            $this->assertSame('preserved', $revision->fresh()->body);
        }
    }

    public function test_real_waiting_connection_allocates_next_revision_after_old_snapshot(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $worker = $this->worker($member, $approach);
        DB::beginTransaction();
        try {
            $first = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'first body'));
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'snapshot')), $worker->getErrorOutput());
            $snapshot = json_decode(explode("\n", trim($worker->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, $snapshot['visible_revisions']);
            $this->assertSame('REPEATABLE-READ', $snapshot['isolation']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $snapshot['connection_id']);
            $waiting = false;
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne("SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'organizations'", [$snapshot['connection_id']])->total > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Observe an actual InnoDB organization-row wait.');
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $events = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($worker->getOutput())));
            $this->assertSame(2, $events[1]['version']);
            $this->assertSame(1, $first->version_number);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->assertSame(['first body', 'second body'], $approach->revisions()->orderBy('version_number')->pluck('body')->all());
    }

    public function test_revocation_current_read_denies_even_while_old_snapshot_still_sees_membership(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        config(['database.connections.approach_revoker' => config('database.connections.mysql')]);
        try {
            $this->trusted($member, function () use ($member, $approach): void {
                DB::beginTransaction();
                try {
                    $this->assertNotNull(DB::table('organization_memberships')->where('id', $member->id)->first());
                    $this->assertSame(1, DB::connection('approach_revoker')->table('organization_memberships')->where('id', $member->id)->delete());
                    $this->assertTrue(app(TenantContext::class)->verifiedFor($member->user));
                    foreach ([fn () => app(ListApproaches::class)->show($member->user, $approach->public_id),
                        fn () => app(ListApproaches::class)->draftHistory($member->user, $approach->public_id),
                        fn () => app(ListApproaches::class)->changeHistory($member->user, $approach->public_id),
                        fn () => app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'rejected'], str_repeat('a', 64)),
                        fn () => app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, (string) Str::ulid(), str_repeat('a', 64)),
                        fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'rejected')] as $operation) {
                        try {
                            $operation();
                            $this->fail('Current membership lock must deny.');
                        } catch (AuthorizationException) {
                            $this->assertDatabaseCount('approach_content_revisions', 0);
                        }
                    }
                } finally {
                    DB::rollBack();
                }
            });
        } finally {
            DB::purge('approach_revoker');
        }
    }

    public function test_post_insert_failure_rolls_back_mysql_revision_and_does_not_consume_a_version(): void
    {
        [$member, $unit] = $this->fixture();
        $approach = $this->create($member, $unit);
        $armed = true;
        Event::listen('eloquent.created: '.ApproachContentRevision::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic post-insert failure.');
            }
        });
        try {
            $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'rolled back'));
            $this->fail('The actual inserted row must roll back.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic post-insert failure.', $error->getMessage());
            $this->assertSame(0, DB::transactionLevel());
            $this->assertDatabaseCount('approach_content_revisions', 0);
        } finally {
            $armed = false;
        }
        $revision = $this->trusted($member, fn () => app(SaveApproachContentRevision::class)->execute($member->user, $approach->public_id, 'committed'));
        $this->assertSame(1, $revision->version_number);
    }

    private function worker(OrganizationMembership $member, Approach $approach, array $mutation = []): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (getenv('MYSQL_TESTS_ENABLED') !== '1' || !$app->environment('testing') || $db->getDriverName() !== 'mysql'
    || $db->getDatabaseName() !== 'quality_platform_ci' || $db->selectOne('SELECT DATABASE() AS n')->n !== 'quality_platform_ci'
    || $app->configurationIsCached() || config('database.connections.mysql.url')) { throw new RuntimeException('Unsafe worker target.'); }
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::findOrFail($input['actor']);
$db->beginTransaction();
try {
    $count = $db->table('approach_content_revisions')->where('approach_id', $input['internal_approach'])->count();
    echo json_encode(['phase'=>'snapshot','visible_revisions'=>$count,'connection_id'=>(int)$db->selectOne('SELECT CONNECTION_ID() AS id')->id,'isolation'=>$db->selectOne('SELECT @@transaction_isolation AS level')->level]).PHP_EOL;
    flush();
    if (isset($input['mutation'])) {
        try {
            app(App\Authorization\TenantContext::class)->run($actor, $input['organization'], function () use ($actor, $input) {
                return $input['mutation'] === 'owner'
                    ? app(App\Actions\Approaches\ChangeApproachOwner::class)->execute($actor, $input['approach'], $input['owner'], $input['token'])
                    : app(App\Actions\Approaches\UpdateApproach::class)->execute($actor, $input['approach'], ['name'=>'second writer'], $input['token']);
            });
            $outcome = 'unexpected-success';
        } catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) {
            $outcome = $error->getStatusCode();
        }
        $db->commit();
        echo json_encode(['phase'=>'finished','outcome'=>$outcome]).PHP_EOL;
        return;
    }
    $revision = app(App\Authorization\TenantContext::class)->run($actor, $input['organization'], fn()=>app(App\Actions\Approaches\SaveApproachContentRevision::class)->execute($actor, $input['approach'], 'second body'));
    $db->commit();
    echo json_encode(['phase'=>'committed','version'=>$revision->version_number]).PHP_EOL;
} finally { while ($db->transactionLevel() > 0) { $db->rollBack(); } }
PHP;
        $config = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'MYSQL_TESTS_ENABLED' => '1',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'quality_platform_ci', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $worker->setInput(json_encode(['actor' => $member->user_id, 'organization' => $member->organization_id,
            'approach' => $approach->public_id, 'internal_approach' => $approach->id] + $mutation, JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);

        return $worker;
    }

    public static function concurrentMutations(): array
    {
        return [['metadata'], ['owner']];
    }

    #[DataProvider('concurrentMutations')]
    public function test_actual_competing_writers_reject_stale_form_after_row_wait(string $operation): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create();
        $approach = $this->create($member, $unit);
        $token = $this->trusted($member, fn () => app(ListApproaches::class)->show($member->user, $approach->public_id))['edit_token'];
        $worker = $this->worker($member, $approach, ['mutation' => $operation, 'token' => $token, 'owner' => $other->public_id]);
        DB::beginTransaction();
        try {
            $this->trusted($member, fn () => $operation === 'owner'
                ? app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $other->public_id, $token)
                : app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'first writer'], $token));
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'snapshot')), $worker->getErrorOutput());
            $snapshot = json_decode(explode("\n", trim($worker->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('REPEATABLE-READ', $snapshot['isolation']);
            $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $snapshot['connection_id']);
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne("SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'organizations'", [$snapshot['connection_id']])->total > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Actual second InnoDB writer must wait before reading the current edit proof.');
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $lines = explode("\n", trim($worker->getOutput()));
            $this->assertSame(409, json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['outcome']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
        $this->assertDatabaseCount('approach_changes', 1);
        $this->assertSame($operation === 'owner' ? $other->id : $unit->id, $approach->fresh()->owning_unit_id);
        $this->assertSame($operation === 'metadata' ? 'first writer' : 'Synthetic approach', $approach->fresh()->name);
        $this->assertDatabaseCount('approach_content_revisions', 0);
    }

    public function test_audit_constraints_restrict_cross_tenant_history_and_validate_json_event_and_owner_shape(): void
    {
        [$a, $unit] = $this->fixture();
        [$b, $foreign] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($a->organization)->create();
        $approach = $this->create($a, $unit);
        $state = $this->trusted($a, fn () => app(ListApproaches::class)->show($a->user, $approach->public_id));
        $this->trusted($a, fn () => app(ChangeApproachOwner::class)->execute($a->user, $approach->public_id, $other->public_id, $state['edit_token']));
        $event = ApproachChange::sole();
        $row = $event->getRawOriginal();
        unset($row['id']);
        $row['public_id'] = (string) Str::ulid();
        foreach (['organization_id' => $b->organization_id, 'previous_owner_id' => $foreign->id, 'new_owner_id' => $foreign->id, 'actor_id' => 99999999] as $field => $value) {
            $this->sqlFailure(fn () => DB::table('approach_changes')->insert(array_replace($row, [$field => $value])), 1452);
        }
        foreach (['event' => 'approved', 'before_state' => '[]', 'after_state' => 'null', 'previous_owner_id' => null, 'new_owner_id' => $unit->id] as $field => $value) {
            $this->sqlFailure(fn () => DB::table('approach_changes')->insert(array_replace($row, [$field => $value])), 3819);
        }
        $this->sqlFailure(fn () => DB::table('approach_changes')->insert(array_replace($row, ['before_state' => '{broken'])), 3140);
        $this->sqlFailure(fn () => DB::table('approach_changes')->insert(array_replace($row, ['public_id' => $event->public_id])), 1062);
        $this->sqlFailure(fn () => DB::table('approaches')->where('id', $approach->id)->delete(), 1451);
        // Previous owner is now retained solely by its audit FK, no longer core owner.
        $this->sqlFailure(fn () => DB::table('organizational_units')->where('id', $unit->id)->delete(), 1451);
        $this->assertSame($row['before_state'], $event->fresh()->getRawOriginal('before_state'));
    }

    public static function auditInsertFaults(): array
    {
        return [['creating', false], ['created', false], ['creating', true], ['created', true]];
    }

    #[DataProvider('auditInsertFaults')]
    public function test_mysql_update_and_audit_insert_failure_are_one_atomic_transaction(string $phase, bool $owner): void
    {
        [$member, $unit] = $this->fixture();
        $other = OrganizationalUnit::factory()->for($member->organization)->create();
        $approach = $this->create($member, $unit);
        $before = $approach->fresh()->getAttributes();
        $token = $this->trusted($member, fn () => app(ListApproaches::class)->show($member->user, $approach->public_id))['edit_token'];
        $armed = true;
        Event::listen('eloquent.'.$phase.': '.ApproachChange::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic MySQL audit failure.');
            }
        });
        try {
            $this->trusted($member, fn () => $owner
                ? app(ChangeApproachOwner::class)->execute($member->user, $approach->public_id, $other->public_id, $token)
                : app(UpdateApproach::class)->execute($member->user, $approach->public_id, ['name' => 'rollback'], $token));
            $this->fail('Audit failure must roll back actual MySQL UPDATE and INSERT.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic MySQL audit failure.', $error->getMessage());
            $this->assertSame($before, $approach->fresh()->getAttributes());
            $this->assertDatabaseCount('approach_changes', 0);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $armed = false;
        }
    }

    public function test_new_audit_atomic_ddl_failure_and_empty_retry_preserve_existing_tables(): void
    {
        $this->rollbackApproachMigrations(1)->assertExitCode(0);
        $this->assertSame(23, DB::table('migrations')->count());
        DB::statement('RENAME TABLE approaches TO audit_parent_fixture');
        $migration = require database_path('migrations/2026_11_08_000001_create_approach_changes_table.php');
        try {
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('approach_changes'));
            $this->assertTrue(Schema::hasTable('approach_content_revisions'));
            $this->assertSame(23, DB::table('migrations')->count());
        } finally {
            DB::statement('RENAME TABLE audit_parent_fixture TO approaches');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->sqlFailure(fn () => $migration->up(), 1050);
        $this->assertTrue(Schema::hasTable('approach_changes'));
        $this->assertSame(26, DB::table('migrations')->count());
    }
}
