<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\DocumentWorkspace;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\MySqlTestCase;

class DocumentWorkspaceConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
        $this->assertSame('REPEATABLE-READ', DB::selectOne('SELECT @@transaction_isolation AS isolation')->isolation);
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentWorkspace();
        parent::tearDown();
    }

    private function child(array $f, string $mode, array $options = []): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=json_decode(getenv('DOCUMENT_CHILD_DATA'),true,flags:JSON_THROW_ON_ERROR);$p=$d['proof'];
$db=Illuminate\Support\Facades\DB::connection();
if(!$app->environment('testing')||getenv('MYSQL_TESTS_ENABLED')!=='1'||$db->getDriverName()!=='mysql'||$db->getDatabaseName()!=='quality_platform_ci'||config('database.connections.mysql.url'))throw new RuntimeException('Unsafe child target');
$actual=$db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version,CONNECTION_ID() AS connection');
if($actual->name!=='quality_platform_ci'||$actual->uuid!==$p['server_uuid']||!str_starts_with($actual->version,'8.4.'))throw new RuntimeException('Wrong child server');
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->statement('SET SESSION innodb_lock_wait_timeout=8');
echo 'CONNECTION:'.$actual->connection."\n";
config(['filesystems.disks.local.root'=>$d['disk_root'],'session.driver'=>'array']);Illuminate\Support\Facades\Storage::forgetDisk('local');
$issuer=App\Models\User::findOrFail($p['issuer_id']);$editor=App\Models\User::findOrFail($p['editor_id']);
$write=in_array($d['mode'],['append','metadata'],true);$actor=$write?$editor:$issuer;
Illuminate\Support\Facades\Auth::guard('institution')->setUser($actor);
app('session.store')->put(['identity.active_guard'=>'institution','institution.organization'=>$d['organization_public_id'],
 'institution.password_hash'=>$write?$d['session_hash']:$issuer->getAuthPassword()]);request()->setLaravelSession(app('session.store'));
echo "READY\n";
try{
 App\Authorization\DocumentWorkspaceAccess::runFixture(request(),$p,function()use($d,$p,$db,$issuer,$editor){
  if($d['mode']==='hold'){
   $db->transaction(function()use($p,$db,$d){$db->table('organizations')->where('id',$p['organization_id'])->lockForUpdate()->first();echo "LOCKED\n";usleep(($d['hold_seconds']??4)*1000000);});
  }elseif($d['mode']==='assignment'){
   app(App\Actions\Documents\ManageDocumentWorkspaceAssignments::class)->revoke($issuer,$d['assignment']);
  }elseif($d['mode']==='append'){
   app(App\Actions\Documents\DocumentWorkspace::class)->append($d['resource']);
  }elseif($d['mode']==='metadata'){
   app(App\Actions\Documents\DocumentWorkspace::class)->updateMetadata($d['resource'],['title'=>$d['title']??'child metadata','description'=>'independent connection'],$d['token']??'none');
  }elseif($d['mode']==='password'){
   $token=Illuminate\Support\Facades\Password::broker('institution')->createToken($editor);
   if(!app(App\Actions\Identity\InstitutionRecovery::class)->reset($d['organization_public_id'],$editor->public_id,$token,'changed-local-fixture-password'))throw new RuntimeException('Actual recovery failed');
  }else{
   app(App\Authorization\TenantContext::class)->run($issuer,$p['organization_id'],function()use($d,$p,$editor,$db){
    $role=App\Models\Role::where('organization_id',$p['organization_id'])->where('name','Document TEST_ONLY '.$p['fixture_id'])->firstOrFail();
    if($d['mode']==='role')app(App\Authorization\RoleManagement::class)->revoke($role,$editor);
    elseif($d['mode']==='permission')app(App\Authorization\RoleManagement::class)->syncPermissions($role,[]);
    elseif($d['mode']==='membership')$db->transaction(function()use($db,$p,$editor){$db->table('model_has_roles')->where('organization_id',$p['organization_id'])->where('model_id',$editor->id)->delete();$db->table('organization_memberships')->where('organization_id',$p['organization_id'])->where('user_id',$editor->id)->delete();});
    else throw new RuntimeException('Unknown document child mode');
   });
  }
 });echo "COMMITTED\n";
}catch(Illuminate\Auth\Access\AuthorizationException){echo "DENIED\n";}
catch(Symfony\Component\HttpKernel\Exception\ConflictHttpException){echo "CONFLICT\n";}
catch(Throwable $e){fwrite(STDERR,get_class($e)."\n");exit(2);}
PHP;

        return new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), ['DOCUMENT_CHILD_DATA' => json_encode($options + [
            'proof' => $f['proof'], 'mode' => $mode, 'organization_public_id' => $f['organization']->public_id,
            'session_hash' => $f['editor']->getAuthPassword(), 'disk_root' => Storage::disk('local')->path(''),
        ], JSON_THROW_ON_ERROR)], timeout: 25);
    }

    private function marker(Process $child, string $marker): void
    {
        $deadline = microtime(true) + 8;
        while (! str_contains($child->getOutput(), $marker) && $child->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString($marker, $child->getOutput(), $child->getErrorOutput());
        preg_match('/CONNECTION:(\d+)/', $child->getOutput(), $match);
        $this->assertNotEmpty($match);
        $this->assertNotSame((int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id, (int) $match[1]);
    }

    private function committed(Process $child): void
    {
        $child->wait();
        $this->assertSame(0, $child->getExitCode(), $child->getErrorOutput());
        $this->assertStringContainsString('COMMITTED', $child->getOutput());
    }

    private function assignment(array $f, string $operation = 'metadata', string $actor = 'editor', ?string $resource = null): string
    {
        return DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f[$actor]->id)
            ->where('operation', $operation)->when($resource !== null, fn ($query) => $query->where('document_id', DB::table('documents')->where('public_id', $resource)->value('id')))->value('public_id');
    }

    public static function revocations(): array
    {
        return [['assignment'], ['role'], ['permission'], ['membership'], ['password']];
    }

    #[DataProvider('revocations')]
    public function test_old_rr_snapshot_cannot_authorize_after_independent_revocation_commits(string $mode): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f, $mode): void {
            $id = $this->createWorkspaceDocument($f);
            $assignment = $this->assignment($f);
            DB::beginTransaction();
            try {
                $old = DB::table('role_has_permissions')->count();
                $this->assertGreaterThan(0, $old);
                $child = $this->child($f, $mode, ['assignment' => $assignment]);
                $child->start();
                $this->marker($child, 'READY');
                $this->committed($child);
                $this->assertSame($old, DB::table('role_has_permissions')->count(), 'Original RR view is deliberately old.');
                try {
                    app(DocumentWorkspace::class)->updateMetadata($id, ['title' => 'must not persist', 'description' => 'revoked'], 'none');
                    $this->fail('Committed '.$mode.' revocation was ignored.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('document_metadata_revisions', 0);
                }
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_sql_listing_count_search_and_page_see_current_resource_revocation_in_old_rr_view(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $first = $this->createWorkspaceDocument($f, 'first');
            $second = $this->createWorkspaceDocument($f, 'second');
            $assignment = $this->assignment($f, 'view', 'reader', $first);
            $this->loginDocumentActor($f['reader'], $f['organization']);
            DB::beginTransaction();
            try {
                $this->assertSame(2, DB::table('document_workspace_assignments')->where('grantee_id', $f['reader']->id)->where('operation', 'view')->whereNull('revoked_at')->count());
                $child = $this->child($f, 'assignment', ['assignment' => $assignment]);
                $child->start();
                $this->marker($child, 'READY');
                $this->committed($child);
                $this->assertSame(2, DB::table('document_workspace_assignments')->where('grantee_id', $f['reader']->id)->where('operation', 'view')->whereNull('revoked_at')->count());
                $page = app(DocumentWorkspace::class)->listing(perPage: 1);
                $this->assertSame(1, $page->total());
                $this->assertSame($second, $page->first()['document_public_id']);
                $this->assertSame(0, app(DocumentWorkspace::class)->listing('first')->total());
            } finally {
                DB::rollBack();
            }
        });
    }

    public function test_current_document_write_commits_before_an_observed_waiting_assignment_revoker(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $assignment = $this->assignment($f);
            $child = null;
            DB::beginTransaction();
            try {
                app(DocumentWorkspace::class)->updateMetadata($id, ['title' => 'committed before revoke', 'description' => 'atomic'], 'none');
                $child = $this->child($f, 'assignment', ['assignment' => $assignment]);
                $child->start();
                $this->marker($child, 'READY');
                preg_match('/CONNECTION:(\d+)/', $child->getOutput(), $match);
                $deadline = microtime(true) + 5;
                do {
                    $wait = DB::selectOne('SELECT COUNT(*) AS n FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID=w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.REQUESTING_ENGINE_LOCK_ID WHERE t.PROCESSLIST_ID=? AND l.OBJECT_NAME=?', [(int) $match[1], 'organizations'])->n;
                    if ($wait > 0) {
                        break;
                    }
                    usleep(10000);
                } while ($child->isRunning() && microtime(true) < $deadline);
                $this->assertGreaterThan(0, $wait, 'Actual engine wait on the agreed organization prefix required.');
                DB::commit();
                $this->committed($child);
                try {
                    app(DocumentWorkspace::class)->updateMetadata($id, ['title' => 'after revoke', 'description' => 'denied'], DB::table('document_metadata_revisions')->value('public_id'));
                    $this->fail('Subsequent revoked metadata write accepted.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('document_metadata_revisions', 1);
                    $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'revoked')->count());
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

    public static function preparationRevocations(): array
    {
        return [['assignment'], ['role'], ['membership'], ['password']];
    }

    #[DataProvider('preparationRevocations')]
    public function test_revocation_after_real_byte_preparation_rejects_append_and_compensates_only_new_file(string $mode): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f, $mode): void {
            $id = $this->createWorkspaceDocument($f);
            $before = Storage::disk('local')->allFiles();
            $disk = $this->barrierDisk();
            $disk->afterPut = function () use ($f, $mode): void {
                $this->assertSame(0, DB::transactionLevel(), 'No transaction may own out-of-transaction file preparation.');
                $child = $this->child($f, $mode, ['assignment' => $this->assignment($f, 'append')]);
                $child->start();
                $this->marker($child, 'READY');
                $this->committed($child);
            };
            try {
                app(DocumentWorkspace::class)->append($id);
                $this->fail('Post-preparation '.$mode.' revocation accepted.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('document_versions', 1);
                $this->assertSame($before, Storage::disk('local')->allFiles());
                if (in_array($mode, ['assignment', 'role'], true)) {
                    $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'access_denied')->count());
                }
            }
        });
    }

    public function test_real_expiry_during_other_connection_lock_wait_after_preparation_cannot_commit_version(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $expiry = now('UTC')->startOfSecond()->addSeconds(3);
            DB::table('document_workspace_assignments')->where('public_id', $this->assignment($f, 'append'))->update([
                'starts_at' => $expiry->copy()->subDay()->format('Y-m-d H:i:s'), 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
            $before = Storage::disk('local')->allFiles();
            $child = null;
            $this->barrierDisk()->afterPut = function () use ($f, &$child, $expiry): void {
                $child = $this->child($f, 'hold', ['hold_seconds' => 5]);
                $child->start();
                $this->marker($child, 'LOCKED');
                $this->assertTrue(now('UTC')->lessThan($expiry));
            };
            $started = microtime(true);
            try {
                app(DocumentWorkspace::class)->append($id);
                $this->fail('Expired append grant committed after wait.');
            } catch (AuthorizationException) {
                $this->assertGreaterThan(4, microtime(true) - $started);
                $this->assertDatabaseCount('document_versions', 1);
                $this->assertSame($before, Storage::disk('local')->allFiles());
                $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'expiration_observed')->count());
                $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'access_denied')->count());
                $this->committed($child);
            } finally {
                if ($child?->isRunning()) {
                    $child->stop();
                }
            }
        });
    }

    public function test_simultaneous_appends_get_distinct_serial_versions_and_stale_metadata_loser_gets_conflict(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $children = [$this->child($f, 'append', ['resource' => $id]), $this->child($f, 'append', ['resource' => $id])];
            DB::beginTransaction();
            try {
                DB::table('organizations')->where('id', $f['organization']->id)->lockForUpdate()->first();
                foreach ($children as $child) {
                    $child->start();
                    $this->marker($child, 'READY');
                }
                DB::commit();
                foreach ($children as $child) {
                    $this->committed($child);
                }
                $this->assertSame([1, 2, 3], DB::table('document_versions')->orderBy('version_number')->pluck('version_number')->all());
                $this->assertSame(3, DB::table('document_versions')->distinct()->count('storage_key'));
                $this->assertCount(3, Storage::disk('local')->allFiles());
                app(DocumentWorkspace::class)->updateMetadata($id, ['title' => 'winning metadata', 'description' => 'kept'], 'none');
                $loser = $this->child($f, 'metadata', ['resource' => $id, 'token' => 'none']);
                $loser->start();
                $this->marker($loser, 'READY');
                $loser->wait();
                $this->assertSame(0, $loser->getExitCode(), $loser->getErrorOutput());
                $this->assertStringContainsString('CONFLICT', $loser->getOutput());
                $this->assertDatabaseCount('document_metadata_revisions', 1);
                $this->assertSame('winning metadata', app(DocumentWorkspace::class)->show($id)['title']);
            } finally {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                foreach ($children as $child) {
                    if ($child->isRunning()) {
                        $child->stop();
                    }
                }
            }
        });
    }

    private function barrierDisk(): DocumentConcurrencyDisk
    {
        $real = Storage::disk('local');
        $disk = new DocumentConcurrencyDisk($real->getDriver(), $real->getAdapter(), $real->getConfig());
        Storage::set('local', $disk);

        return $disk;
    }
}

/** Real storage write with one deterministic test-only process barrier afterward. */
final class DocumentConcurrencyDisk extends FilesystemAdapter
{
    public ?Closure $afterPut = null;

    public function put($path, $contents, $options = [])
    {
        $result = parent::put($path, $contents, $options);
        $callback = $this->afterPut;
        $this->afterPut = null;
        if ($result && $callback !== null) {
            $callback();
        }

        return $result;
    }
}
