<?php

namespace Tests\Integration\MySql;

use App\Filament\Institution\Approaches\ApproachAccess;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;

class ApproachAssignmentConcurrencyTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations;

    protected function tearDown(): void
    {
        if ($this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            $this->cleanupAssignmentFixtures();
        }
        parent::tearDown();
    }

    private function child(array $fixture, string $mode, ?string $resource = null, bool $signal = false): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php'; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=json_decode(getenv('J03_CHILD_DATA'),true,flags:JSON_THROW_ON_ERROR); $p=$d['proof'];
$db=Illuminate\Support\Facades\DB::connection();
if(!$app->environment('testing')||getenv('MYSQL_TESTS_ENABLED')!=='1'||$db->getDatabaseName()!=='quality_platform_ci'||$db->selectOne('SELECT @@server_uuid AS uuid')->uuid!==$p['server_uuid'])throw new RuntimeException('Unsafe child target');
$i=App\Models\User::findOrFail($p['issuer_id']); $o=App\Models\User::findOrFail($p['contributor_id']);
$role=App\Models\Role::where('organization_id',$p['organization_id'])->where('name','j03-test-'.$p['fixture_id'])->firstOrFail();
if($d['signal'])echo "ATTEMPT\n";
App\Authorization\ApproachAssignmentAccess::runFixture(request(),$p,function()use($d,$p,$i,$o,$role){
 App(App\Authorization\TenantContext::class)->run($i,$p['organization_id'],function()use($d,$p,$o,$role){
  if($d['mode']==='hold'){
   Illuminate\Support\Facades\DB::transaction(function()use($p){Illuminate\Support\Facades\DB::table('organizations')->where('id',$p['organization_id'])->lockForUpdate()->first();echo "LOCKED\n";usleep(4000000);});
  }elseif($d['mode']==='role')App(App\Authorization\RoleManagement::class)->revoke($role,$o);
  elseif($d['mode']==='permission')App(App\Authorization\RoleManagement::class)->syncPermissions($role,[]);
  elseif($d['mode']==='membership'){
   Illuminate\Support\Facades\DB::transaction(function()use($p,$o){Illuminate\Support\Facades\DB::table('model_has_roles')->where('organization_id',$p['organization_id'])->where('model_id',$o->id)->delete();Illuminate\Support\Facades\DB::table('organization_memberships')->where('organization_id',$p['organization_id'])->where('user_id',$o->id)->delete();});
  }elseif($d['mode']==='assignment'){
   $q=App\Models\User::findOrFail($p['quality_id']); $r=App\Models\Approach::where('public_id',$d['resource'])->where('organization_id',$p['organization_id'])->firstOrFail();
   App(App\Authorization\TenantContext::class)->run($q,$p['organization_id'],fn()=>App(App\Actions\Approaches\ManageApproachAssignments::class)->revokeContributor($q,$r,$o));
  }else throw new RuntimeException('Unknown test operation');
 });
});
echo "COMMITTED\n";
PHP;

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(),
            ['J03_CHILD_DATA' => json_encode(['proof' => $fixture['proof'], 'mode' => $mode, 'resource' => $resource, 'signal' => $signal], JSON_THROW_ON_ERROR)], timeout: 15);
    }

    public static function revocations(): array
    {
        return [['role'], ['permission'], ['membership'], ['assignment']];
    }

    #[DataProvider('revocations')]
    public function test_old_rr_snapshot_cannot_authorize_after_child_revocation_commits(string $mode): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f, $mode): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            DB::beginTransaction();
            try {
                $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $f['contributor']->id)->count());
                $child = $this->child($f, $mode, $id);
                $child->run();
                $this->assertSame(0, $child->getExitCode(), 'Child operation failed: '.$child->getErrorOutput());
                $this->assertSame("COMMITTED\n", $child->getOutput());
                $this->assertSame(1, DB::table('model_has_roles')->where('model_id', $f['contributor']->id)->count(), 'Original RR read view remains old.');
                $denied = false;
                try {
                    app(ApproachAccess::class)->saveDraft($id, 'must not persist after revoke', 'none');
                } catch (AuthorizationException) {
                    $denied = true;
                }
                $this->assertTrue($denied, 'Actual Policy accepted a committed '.$mode.' revocation through an old RR snapshot.');
                $this->assertDatabaseCount('approach_content_revisions', 0);
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_list_total_and_page_use_current_resource_assignments_inside_old_rr_snapshot(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $first = $this->createAssignedApproach($f, 'first');
            $second = $this->createAssignedApproach($f, 'second');
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            DB::beginTransaction();
            try {
                $this->assertSame(2, DB::table('approach_assignments')->where('grantee_id', $f['contributor']->id)->where('operation', 'view')->whereNull('revoked_at')->count());
                $child = $this->child($f, 'assignment', $first);
                $child->run();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertSame(2, DB::table('approach_assignments')->where('grantee_id', $f['contributor']->id)->where('operation', 'view')->whereNull('revoked_at')->count());
                $result = app(ApproachAccess::class)->listing(perPage: 1);
                $this->assertSame(1, $result->total());
                $this->assertSame($second, $result->first()['public_id']);
                $this->assertCount(0, app(ApproachAccess::class)->listing(search: 'first')->items());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_write_holding_current_grants_commits_before_waiting_role_revoker_then_later_write_denies(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            $child = null;
            DB::beginTransaction();
            try {
                app(ApproachAccess::class)->saveDraft($id, 'commits before revoke', 'none');
                $child = $this->child($f, 'role', signal: true);
                $child->start();
                $deadline = microtime(true) + 5;
                while (! str_contains($child->getOutput(), 'ATTEMPT') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('ATTEMPT', $child->getOutput());
                usleep(100000);
                $this->assertTrue($child->isRunning(), 'Revoker must wait for the locked role/member rows.');
                DB::commit();
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertStringContainsString('COMMITTED', $child->getOutput());
                try {
                    app(ApproachAccess::class)->saveDraft($id, 'after revoke', 'none');
                    $this->fail('A subsequent write must deny.');
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

    public function test_assignment_expiring_while_waiting_for_other_process_lock_cannot_persist_a_draft(): void
    {
        $f = $this->assignmentFixture();
        $this->inAssignmentFixture($f, function () use ($f): void {
            $id = $this->createAssignedApproach($f);
            $this->loginAssignmentActor($f['contributor'], $f['organization']);
            // A real wall-clock boundary in a disposable fixture; preserve its exact 24h interval.
            $expiry = now('UTC')->startOfSecond()->addSeconds(2);
            DB::table('approach_assignments')->where('fixture_id', $f['proof']['fixture_id'])
                ->where('grantee_id', $f['contributor']->id)->update([
                    'starts_at' => $expiry->copy()->subDay()->format('Y-m-d H:i:s'),
                    'expires_at' => $expiry->format('Y-m-d H:i:s'),
                ]);
            $child = $this->child($f, 'hold');
            $child->start();
            try {
                $deadline = microtime(true) + 5;
                while (! str_contains($child->getOutput(), 'LOCKED') && $child->isRunning() && microtime(true) < $deadline) {
                    usleep(10000);
                }
                $this->assertStringContainsString('LOCKED', $child->getOutput());
                $this->assertTrue(now('UTC')->lessThan($expiry), 'Grant must still be valid before waiting.');
                $started = microtime(true);
                try {
                    app(ApproachAccess::class)->saveDraft($id, 'must not survive expiry during wait', 'none');
                    $this->fail('Expired authority persisted a draft after the lock wait.');
                } catch (AuthorizationException) {
                    $this->assertGreaterThan(2, microtime(true) - $started, 'Actually waited for the separate process.');
                    $this->assertDatabaseCount('approach_content_revisions', 0);
                    $this->assertSame(1, DB::table('approach_assignment_changes')->where('fixture_id', $f['proof']['fixture_id'])->where('event', 'expiration_observed')->count());
                }
                $child->wait();
                $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
                $this->assertSame("LOCKED\nCOMMITTED\n", $child->getOutput());
            } finally {
                if ($child->isRunning()) {
                    $child->stop();
                }
            }
        });
    }
}
