<?php

namespace Tests\Integration\MySql;

use App\Actions\Maturity\ManageStageReassessmentOperations;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;

class ClosureReassessmentWorkflowTest extends \Tests\Feature\Maturity\ClosureReassessmentWorkflowTest
{
    private function openWorker(array $fixture, object $stage): Process
    {
        $state = $this->state($fixture)['stages'][$stage->stage];
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Support/closure-action-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'LOG_CHANNEL' => 'null', 'DB_CONNECTION' => 'mysql', 'DB_URL' => '',
            'DB_HOST' => DB::connection()->getConfig('host'), 'DB_PORT' => (string) DB::connection()->getConfig('port'),
            'DB_DATABASE' => 'quality_platform_ci', 'DB_USERNAME' => DB::connection()->getConfig('username'),
            'DB_PASSWORD' => DB::connection()->getConfig('password'), 'MYSQL_TESTS_ENABLED' => '1',
        ]);
        $worker->setInput(json_encode(['mode' => 'open_reassessment', 'proof' => $this->workflowPanelProof($fixture),
            'actor' => $fixture['author']->id, 'session_hash' => $fixture['author']->getAuthPassword(),
            'approach' => $fixture['approach']->public_id, 'stage' => $stage->public_id,
            'expected_assessment' => $state['assessment_public_id'], 'request_key' => (string) Str::ulid()], JSON_THROW_ON_ERROR));
        $worker->setTimeout(45);

        return $worker;
    }

    private function awaitOrganizationLock(Process $worker): int
    {
        $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'READY')), $worker->getErrorOutput());
        $this->assertStringContainsString('ISOLATION:REPEATABLE-READ', $worker->getOutput());
        $this->assertSame(1, preg_match('/CONNECTION:(\d+)/', $worker->getOutput(), $matches));
        $id = (int) $matches[1];
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, $id);
        $waiting = false;
        $deadline = microtime(true) + 15;
        do {
            $waiting = (int) DB::selectOne("SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w
                JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID
                JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE=w.ENGINE
                WHERE t.PROCESSLIST_ID=? AND l.OBJECT_SCHEMA=DATABASE() AND l.OBJECT_NAME='organizations'", [$id])->n > 0;
            if (! $waiting) {
                usleep(20000);
            }
        } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
        $this->assertTrue($waiting, 'Prove the actual second connection waits on the bounded organization-first protocol.');

        return $id;
    }

    public function test_current_reassessment_revocation_wins_while_an_actual_action_waits_on_the_shared_boundary(): void
    {
        $fixture = $this->completeCycle();
        $stage = $fixture['stages'][0];
        $worker = $this->openWorker($fixture, $stage);
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $fixture['organization']->id)->lockForUpdate()->first();
            $worker->start();
            $this->awaitOrganizationLock($worker);
            $grant = DB::table('maturity_reassessment_operation_assignments')->where('stage_id', $stage->id)
                ->where('grantee_id', $fixture['author']->id)->where('operation', 'open_reassessment')->sole();
            $this->asWorkflow($fixture, 'issuer', fn () => app(ManageStageReassessmentOperations::class)->revoke($grant->public_id));
            DB::commit();
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertStringContainsString('DENIED', $worker->getOutput());
            $this->assertDatabaseCount('maturity_reassessment_attempts', 0);
            $this->assertDatabaseHas('maturity_reassessment_operation_changes', ['event' => 'revoked', 'assignment_id' => $grant->id]);
            $this->assertDatabaseHas('maturity_reassessment_operation_changes', ['event' => 'access_denied', 'assignment_id' => $grant->id]);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
    }

    public function test_two_actual_connections_cannot_open_two_active_reassessments_for_one_stage(): void
    {
        $fixture = $this->completeCycle();
        $workers = [$this->openWorker($fixture, $fixture['stages'][0]), $this->openWorker($fixture, $fixture['stages'][0])];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $fixture['organization']->id)->lockForUpdate()->first();
            foreach ($workers as $worker) {
                $worker->start();
                $this->awaitOrganizationLock($worker);
            }
            DB::commit();
            $outcomes = [];
            foreach ($workers as $worker) {
                $worker->wait();
                $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
                $this->assertSame(1, preg_match('/^(COMMITTED|CONFLICT)$/m', $worker->getOutput(), $matches));
                $outcomes[] = trim($matches[1]);
            }
            sort($outcomes);
            $this->assertSame(['COMMITTED', 'CONFLICT'], $outcomes);
            $this->assertDatabaseCount('maturity_reassessment_attempts', 1);
            $this->assertDatabaseCount('maturity_reassessment_events', 1);
            $this->assertDatabaseHas('maturity_reassessment_attempts', ['state' => 'open']);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop();
                }
            }
        }
    }

    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $db = $app['db']->connection();
        if (! $app->environment('testing') || getenv('MYSQL_TESTS_ENABLED') !== '1' || $app->configurationIsCached()
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'quality_platform_ci' || $db->getConfig('url')) {
            throw new RuntimeException('Closure reassessment requires an opted-in owned disposable MySQL database.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version');
        if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
            || (getenv('MVP_FIXTURE_MYSQL_UUID') && ! hash_equals(getenv('MVP_FIXTURE_MYSQL_UUID'), $actual->uuid))) {
            throw new RuntimeException('Closure reassessment actual MySQL identity mismatch.');
        }

        return $app;
    }
}
