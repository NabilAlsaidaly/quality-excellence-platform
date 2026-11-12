<?php

namespace Tests\Integration\MySql;

use App\Actions\Maturity\ConfigureMaturityStage;
use App\Actions\Maturity\SaveStageSubmission;
use App\Actions\Reviews\DecideReview;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;
use Tests\Support\ReviewMaturityFixtures;

/** Real independent RR connections; persistence races, not privileged Action or global T17 acceptance. */
class StageReassessmentConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    private array $children = [];

    protected function tearDown(): void
    {
        foreach ($this->children as $child) {
            if ($child->isRunning()) {
                $child->stop();
            }
        }
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->workflowFixtures as $fixture) {
            foreach (['maturity_reassessment_events', 'maturity_reassessment_decisions', 'maturity_reassessment_attempts'] as $table) {
                if (Schema::hasTable($table)) {
                    foreach (DB::table($table)->where('organization_id', $fixture['organization']->id)
                        ->where('fixture_id', $fixture['proof']['fixture_id'])->orderByDesc('id')->pluck('id') as $id) {
                        DB::table($table)->where('id', $id)->delete();
                    }
                }
            }
        }
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    private function reassessmentFixture(): array
    {
        $f = $this->reviewMaturityFixture();
        $stage = DB::table('maturity_workflow_stages')->where('approach_id', $f['approach']->id)->orderBy('stage_ordinal')->first();
        $question = (string) Str::ulid();
        $data = ['questions' => [['public_id' => $question, 'text' => 'سؤال اختبار الاستمرارية', 'instructions' => '',
            'answer_required' => true, 'active' => true, 'expected_evidence' => [
                ['public_id' => (string) Str::ulid(), 'description' => 'شاهد اختياري للاختبار', 'required' => false]]]],
            'scale' => [['name' => 'بداية', 'description' => '', 'lower_bound' => 0, 'upper_bound' => 30],
                ['name' => 'تقدم', 'description' => '', 'lower_bound' => 31, 'upper_bound' => 100]]];
        $configuration = $this->asWorkflow($f, 'reviewer', fn () => app(ConfigureMaturityStage::class)
            ->execute($f['approach']->public_id, $stage->public_id, $data, 'none'));
        $review = $this->asWorkflow($f, 'author', fn () => app(SaveStageSubmission::class)->execute(
            $f['approach']->public_id, $stage->public_id, [$question => 'إجابة محفوظة'], [], 'none',
            $configuration->public_id, (string) Str::ulid()));
        $this->asWorkflow($f, 'reviewer', fn () => app(DecideReview::class)->execute(
            $review->public_id, $review->fingerprint, 'approved', 'قرار أولي مستقل محفوظ.', (string) Str::ulid(), 1));
        $initial = DB::table('maturity_workflow_assessments')->where('stage_id', $stage->id)->first();

        return $f + compact('stage', 'configuration', 'review', 'initial');
    }

    private function insertAttempt(array $f, ?object $previous = null): object
    {
        $id = DB::table('maturity_reassessment_attempts')->insertGetId([
            'public_id' => (string) Str::ulid(), 'organization_id' => $f['organization']->id,
            'fixture_id' => $f['proof']['fixture_id'], 'approach_id' => $f['approach']->id,
            'stage_id' => $f['stage']->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
            'initial_assessment_id' => $f['initial']->id, 'previous_attempt_id' => $previous?->id,
            'attempt_number' => ($previous?->attempt_number ?? 0) + 1, 'author_id' => $f['author']->id,
            'configuration_id' => $f['configuration']->id, 'request_key' => (string) Str::ulid(),
            'request_hash' => hash('sha256', 'attempt-'.Str::ulid()), 'state' => 'open', 'revision' => 1,
        ]);

        return DB::table('maturity_reassessment_attempts')->where('id', $id)->first();
    }

    private function lockChain(array $f): void
    {
        DB::table('organizations')->where('id', $f['organization']->id)->lockForUpdate()->first();
        DB::table('users')->where('id', $f['author']->id)->lockForUpdate()->first();
        DB::table('organization_memberships')->where('organization_id', $f['organization']->id)
            ->where('user_id', $f['author']->id)->lockForUpdate()->first();
        DB::table('approaches')->where('organization_id', $f['organization']->id)->where('id', $f['approach']->id)->lockForUpdate()->first();
        DB::table('maturity_workflow_stages')->where('organization_id', $f['organization']->id)
            ->where('fixture_id', $f['proof']['fixture_id'])->where('approach_id', $f['approach']->id)
            ->orderBy('stage_ordinal')->lockForUpdate()->get();
    }

    private function child(array $f, string $mode, int $attemptId, array $row = []): Process
    {
        $code = <<<'PHP'
if(getenv('MYSQL_TESTS_ENABLED')!=='1'||getenv('APP_ENV')!=='testing')throw new RuntimeException('Unsafe child opt-in');
$d=json_decode(getenv('REASSESSMENT_CHILD'),true,flags:JSON_THROW_ON_ERROR);
$db=new PDO('mysql:host='.getenv('R_DB_HOST').';port='.getenv('R_DB_PORT').';dbname=quality_platform_ci;charset=utf8mb4',getenv('R_DB_USER'),getenv('R_DB_PASSWORD'),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false]);
$a=$db->query('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version,CONNECTION_ID() AS connection')->fetch(PDO::FETCH_ASSOC);
if($a['name']!=='quality_platform_ci'||$a['uuid']!==$d['uuid']||!str_starts_with($a['version'],'8.4.'))throw new RuntimeException('Wrong owned server');
$db->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->exec('SET SESSION innodb_lock_wait_timeout=8');
echo 'CONNECTION:'.$a['connection']."\nREADY\n";flush();
try{
 $db->beginTransaction();
 $lock=function($sql,$args)use($db){$s=$db->prepare($sql);$s->execute($args);return $s->fetchAll(PDO::FETCH_ASSOC);};
 $lock('SELECT id FROM organizations WHERE id=? FOR UPDATE',[$d['organization']]);
 $lock('SELECT id FROM users WHERE id=? AND organization_id=? FOR UPDATE',[$d['actor'],$d['organization']]);
 $lock('SELECT id FROM organization_memberships WHERE user_id=? AND organization_id=? FOR UPDATE',[$d['actor'],$d['organization']]);
 $lock('SELECT id FROM approaches WHERE id=? AND organization_id=? FOR UPDATE',[$d['approach'],$d['organization']]);
 $lock('SELECT id FROM maturity_workflow_stages WHERE organization_id=? AND fixture_id=? AND approach_id=? ORDER BY stage_ordinal FOR UPDATE',[$d['organization'],$d['fixture'],$d['approach']]);
 if($d['mode']==='open'){
  $allowed=['public_id','organization_id','fixture_id','approach_id','stage_id','created_at','initial_assessment_id','previous_attempt_id','attempt_number','author_id','configuration_id','approved_submission_id','approved_review_id','request_key','request_hash','state','revision','scenario','classification'];
  if(array_diff(array_keys($d['row']),$allowed))throw new RuntimeException('Unexpected fixture fields');
  $keys=array_keys($d['row']);$s=$db->prepare('INSERT INTO maturity_reassessment_attempts ('.implode(',',$keys).') VALUES ('.implode(',',array_fill(0,count($keys),'?')).')');$s->execute(array_values($d['row']));
 }else{
  $rows=$lock('SELECT id,revision FROM maturity_reassessment_attempts WHERE organization_id=? AND fixture_id=? AND approach_id=? AND id=? FOR UPDATE',[$d['organization'],$d['fixture'],$d['approach'],$d['attempt']]);
  if(count($rows)!==1||(int)$rows[0]['revision']!==1){$db->rollBack();echo "CONFLICT\n";exit(0);}
  $s=$db->prepare('UPDATE maturity_reassessment_attempts SET revision=2 WHERE id=? AND revision=1');$s->execute([$d['attempt']]);
  if($s->rowCount()!==1)throw new RuntimeException('CAS failed');
 }
 $db->commit();echo "COMMITTED\n";
}catch(PDOException $e){if($db->inTransaction())$db->rollBack();if(($e->errorInfo[1]??0)===1062){echo "CONFLICT\n";exit(0);}fwrite(STDERR,'SQL_CODE:'.($e->errorInfo[1]??0)."\n");exit(2);}
PHP;
        $db = DB::connection();
        $child = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'MYSQL_TESTS_ENABLED' => '1',
            'R_DB_HOST' => $db->getConfig('host'), 'R_DB_PORT' => (string) $db->getConfig('port'),
            'R_DB_USER' => $db->getConfig('username'), 'R_DB_PASSWORD' => $db->getConfig('password'),
            'REASSESSMENT_CHILD' => json_encode(['mode' => $mode, 'attempt' => $attemptId, 'row' => $row,
                'uuid' => $db->selectOne('SELECT @@server_uuid AS uuid')->uuid, 'organization' => $f['organization']->id,
                'actor' => $f['author']->id, 'approach' => $f['approach']->id, 'fixture' => $f['proof']['fixture_id']], JSON_THROW_ON_ERROR),
        ], timeout: 20);
        $this->children[] = $child;
        $child->start();

        return $child;
    }

    private function ready(Process $child): int
    {
        $deadline = microtime(true) + 6;
        while (! str_contains($child->getOutput(), 'READY') && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('READY', $child->getOutput(), $child->getErrorOutput());
        preg_match('/CONNECTION:(\d+)/', $child->getOutput(), $m);
        $this->assertNotEmpty($m);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $m[1]);

        return (int) $m[1];
    }

    private function observedWait(int $connection): void
    {
        $deadline = microtime(true) + 4;
        do {
            $waiting = DB::selectOne('SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID WHERE t.PROCESSLIST_ID=?', [$connection])->total;
            if ($waiting > 0) {
                break;
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->assertGreaterThan(0, $waiting, 'The independent writer must actually wait on the approved lock chain.');
    }

    private function finished(Process $child, string $outcome): void
    {
        $this->assertSame(0, $child->wait(), $child->getErrorOutput());
        $this->assertStringContainsString($outcome, $child->getOutput());
    }

    public function test_observed_lock_wait_makes_a_stale_attempt_writer_conflict(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        DB::beginTransaction();
        try {
            $this->lockChain($f);
            DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
            $child = $this->child($f, 'update', $attempt->id);
            $this->observedWait($this->ready($child));
            $this->assertSame(1, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->where('revision', 1)->update(['revision' => 2]));
            DB::commit();
            $this->finished($child, 'CONFLICT');
            $this->assertSame(2, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->value('revision'));
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_an_old_rr_snapshot_cannot_hide_the_current_attempt_revision(): void
    {
        $f = $this->reassessmentFixture();
        $attempt = $this->insertAttempt($f);
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        try {
            $this->assertSame(1, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->value('revision'));
            $child = $this->child($f, 'update', $attempt->id);
            $this->ready($child);
            $this->finished($child, 'COMMITTED');
            $this->assertSame(1, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->value('revision'));
            $this->lockChain($f);
            $current = DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->lockForUpdate()->first();
            $this->assertSame(2, $current->revision);
            $this->assertSame(0, DB::table('maturity_reassessment_attempts')->where('id', $attempt->id)->where('revision', 1)->update(['revision' => 3]));
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    public function test_concurrent_opening_keeps_one_attempt_and_the_original_history(): void
    {
        $f = $this->reassessmentFixture();
        $before = (array) $f['initial'];
        DB::beginTransaction();
        try {
            $this->lockChain($f);
            $attempt = $this->insertAttempt($f);
            $row = (array) $attempt;
            foreach (['id', 'previous_attempt_number', 'previous_approved_state', 'active_slot'] as $generated) {
                unset($row[$generated]);
            }
            $row['public_id'] = (string) Str::ulid();
            $row['request_key'] = (string) Str::ulid();
            $child = $this->child($f, 'open', $attempt->id, $row);
            $this->observedWait($this->ready($child));
            DB::commit();
            $this->finished($child, 'CONFLICT');
            $this->assertSame(1, DB::table('maturity_reassessment_attempts')->where('stage_id', $f['stage']->id)->count());
            $this->assertSame($before, (array) DB::table('maturity_workflow_assessments')->where('id', $f['initial']->id)->first());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }
}
