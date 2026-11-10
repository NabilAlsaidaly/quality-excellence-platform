<?php

namespace Tests\Integration\MySql;

use App\Actions\Reviews\SaveReviewableApproach;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;
use Tests\Support\ReviewMaturityFixtures;

class ReviewMaturityConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations, ReviewMaturityFixtures;

    protected function tearDown(): void
    {
        $this->cleanupReviewMaturity();
        parent::tearDown();
    }

    private function child(array $f, string $mode, array $options = []): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=json_decode(getenv('REVIEW_MATURITY_CHILD'),true,flags:JSON_THROW_ON_ERROR);$p=$d['proof']['workflow'];
$db=Illuminate\Support\Facades\DB::connection();
if(!$app->environment('testing')||getenv('MYSQL_TESTS_ENABLED')!=='1'||$db->getDriverName()!=='mysql'||$db->getDatabaseName()!=='quality_platform_ci'||config('database.connections.mysql.url'))throw new RuntimeException('Unsafe child target');
$actual=$db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version,CONNECTION_ID() AS connection');
if($actual->name!=='quality_platform_ci'||$actual->uuid!==$p['server_uuid']||!str_starts_with($actual->version,'8.4.'))throw new RuntimeException('Wrong child server');
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->statement('SET SESSION innodb_lock_wait_timeout=8');
echo 'CONNECTION:'.$actual->connection."\n";
config(['filesystems.disks.local.root'=>$d['disk_root'],'session.driver'=>'array']);Illuminate\Support\Facades\Storage::forgetDisk('local');
Illuminate\Support\Facades\Gate::policy(App\Models\ReviewMaturityReview::class,App\Policies\ReviewMaturityPolicy::class);
$issuer=App\Models\User::findOrFail($p['issuer_id']);$author=App\Models\User::findOrFail($p['author_id']);
$actor=$d['mode']==='write'?$author:$issuer;
Illuminate\Support\Facades\Auth::guard('institution')->setUser($actor);
app('session.store')->put(['identity.active_guard'=>'institution','institution.organization'=>$d['organization'],
 'institution.password_hash'=>$d['mode']==='write'?$d['session_hash']:$issuer->getAuthPassword()]);request()->setLaravelSession(app('session.store'));
echo "READY\n";
try{
 App\Authorization\ApproachAssignmentAccess::runFixture(request(),$d['proof']['approach'],fn()=>
 App\Authorization\DocumentWorkspaceAccess::runFixture(request(),$d['proof']['document'],fn()=>
 App\Authorization\ReviewMaturityAccess::runFixture(request(),$p,function()use($d,$p,$db,$issuer,$author){
  if($d['mode']==='assignment')app(App\Actions\Reviews\ManageReviewMaturityAssignments::class)->revoke($d['assignment']);
  elseif($d['mode']==='write')app(App\Actions\Reviews\SaveReviewableApproach::class)->execute($d['approach'],'concurrent child content',$d['token']??'none',$d['request_key']);
  elseif($d['mode']==='password'){
   $token=Illuminate\Support\Facades\Password::broker('institution')->createToken($author);
   if(!app(App\Actions\Identity\InstitutionRecovery::class)->reset($d['organization'],$author->public_id,$token,'changed-local-fixture-password'))throw new RuntimeException('Actual recovery failed');
  }else app(App\Authorization\TenantContext::class)->run($issuer,$p['organization_id'],function()use($d,$p,$author,$db){
   $role=App\Models\Role::where('organization_id',$p['organization_id'])->where('name','Workflow TEST_ONLY '.$p['fixture_id'])->firstOrFail();
   if($d['mode']==='role')app(App\Authorization\RoleManagement::class)->revoke($role,$author);
   elseif($d['mode']==='permission')app(App\Authorization\RoleManagement::class)->syncPermissions($role,[]);
   elseif($d['mode']==='membership')$db->transaction(function()use($p,$author,$db){$db->table('model_has_roles')->where('organization_id',$p['organization_id'])->where('model_id',$author->id)->delete();$db->table('organization_memberships')->where('organization_id',$p['organization_id'])->where('user_id',$author->id)->delete();});
   else throw new RuntimeException('Unknown child mode');
  });
 })));
 echo "COMMITTED\n";
}catch(Illuminate\Auth\Access\AuthorizationException){echo "DENIED\n";}
catch(Symfony\Component\HttpKernel\Exception\ConflictHttpException){echo "CONFLICT\n";}
catch(Throwable $e){fwrite(STDERR,get_class($e)."\n");exit(2);}
PHP;

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), [
            'REVIEW_MATURITY_CHILD' => json_encode($options + ['proof' => $this->workflowPanelProof($f), 'mode' => $mode,
                'organization' => $f['organization']->public_id, 'approach' => $f['approach']->public_id,
                'session_hash' => $f['author']->getAuthPassword(), 'disk_root' => Storage::disk('local')->path(''), 'request_key' => (string) Str::ulid()], JSON_THROW_ON_ERROR),
        ], timeout: 25);
    }

    private function ready(Process $child): int
    {
        $deadline = microtime(true) + 8;
        while (! str_contains($child->getOutput(), 'READY') && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('READY', $child->getOutput(), $child->getErrorOutput());
        preg_match('/CONNECTION:(\d+)/', $child->getOutput(), $match);
        $this->assertNotEmpty($match);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $match[1]);

        return (int) $match[1];
    }

    public static function revocations(): array
    {
        return [['assignment'], ['role'], ['permission'], ['membership'], ['password']];
    }

    #[DataProvider('revocations')]
    public function test_old_rr_snapshot_denies_after_independent_actual_revocation(string $mode): void
    {
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation);
        $f = $this->reviewMaturityFixture();
        $grant = DB::table('review_maturity_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f['author']->id)->where('operation', 'edit_content')->first();
        $this->loginWorkflowActor($f, 'author');
        $this->inWorkflow($f, function () use ($f, $mode, $grant): void {
            DB::beginTransaction();
            try {
                $old = DB::table('review_maturity_assignments')->whereNull('revoked_at')->count();
                $child = $this->child($f, $mode, ['assignment' => $grant->public_id]);
                $child->start();
                $this->ready($child);
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('COMMITTED', $child->getOutput());
                $this->assertSame($old, DB::table('review_maturity_assignments')->whereNull('revoked_at')->count(), 'Deliberately retained old RR read view.');
                try {
                    app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'must not persist', 'none', (string) Str::ulid());
                    $this->fail('Committed '.$mode.' revocation ignored.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('approach_content_revisions', 0);
                    $this->assertDatabaseCount('review_maturity_reviews', 0);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_observed_writer_lock_orders_revoker_and_leaves_atomic_origin_delivery(): void
    {
        $f = $this->reviewMaturityFixture();
        $grant = DB::table('review_maturity_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f['author']->id)->where('operation', 'edit_content')->first();
        $this->loginWorkflowActor($f, 'author');
        $this->inWorkflow($f, function () use ($f, $grant): void {
            $child = null;
            DB::beginTransaction();
            try {
                app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'commits first', 'none', (string) Str::ulid());
                $child = $this->child($f, 'assignment', ['assignment' => $grant->public_id]);
                $child->start();
                $connection = $this->ready($child);
                $deadline = microtime(true) + 5;
                do {
                    $wait = DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE t.PROCESSLIST_ID=? AND l.OBJECT_NAME=?', [$connection, 'organizations'])->n;
                    if ($wait > 0) {
                        break;
                    } usleep(10000);
                } while ($child->isRunning() && microtime(true) < $deadline);
                $this->assertGreaterThan(0, $wait, 'Actual InnoDB wait on the organization lock prefix.');
                DB::commit();
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('COMMITTED', $child->getOutput());
                $this->assertDatabaseCount('approach_content_revisions', 1);
                $this->assertDatabaseCount('review_maturity_reviews', 1);
                $this->assertDatabaseCount('review_maturity_notifications', 1);
                try {
                    app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'denied after revoke', 'none', (string) Str::ulid());
                    $this->fail('Revoked write succeeded.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('approach_content_revisions', 1);
                }
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($child?->isRunning()) {
                    $child->stop();
                }
            }
        });
    }

    public function test_concurrent_writers_produce_one_revision_review_and_notice_and_one_conflict(): void
    {
        $f = $this->reviewMaturityFixture();
        $this->asWorkflow($f, 'author', function () use ($f): void {
            $child = null;
            DB::beginTransaction();
            try {
                app(SaveReviewableApproach::class)->execute($f['approach']->public_id, 'first committed revision', 'none', (string) Str::ulid());
                $child = $this->child($f, 'write');
                $child->start();
                $this->ready($child);
                DB::commit();
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('CONFLICT', $child->getOutput());
                $this->assertDatabaseCount('approach_content_revisions', 1);
                $this->assertDatabaseCount('review_maturity_reviews', 1);
                $this->assertDatabaseCount('review_maturity_notifications', 1);
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                if ($child?->isRunning()) {
                    $child->stop();
                }
            }
        });
    }
}
