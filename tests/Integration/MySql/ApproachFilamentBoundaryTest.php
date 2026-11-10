<?php

namespace Tests\Integration\MySql;

use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Approach;
use App\Models\ApproachContentRevision;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Process\Process;
use Tests\Feature\Filament\ApproachPanelFixtures;
use Tests\Feature\Filament\SyntheticApproachPolicy;
use Tests\Support\MySqlTestCase;

require_once dirname(__DIR__, 2).'/Feature/Filament/ApproachFilamentIntegrationTest.php';

class ApproachFilamentBoundaryTest extends MySqlTestCase
{
    use ApproachPanelFixtures, DatabaseMigrations;

    private array $approaches44 = [];

    private array $users44 = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('ar');
        SyntheticApproachPolicy::$grants = [];
        app()->bind(ApproachPolicy::class, SyntheticApproachPolicy::class);
        Event::listen('eloquent.created: '.Approach::class, function (Approach $row): void {
            $this->approaches44[] = $row->id;
        });
        Event::listen('eloquent.created: '.User::class, function (User $row): void {
            $this->users44[] = $row->id;
        });
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (DB::connection()->getDatabaseName() !== 'quality_platform_ci' || getenv('MYSQL_TESTS_ENABLED') !== '1') {
            throw new RuntimeException('Refuse cleanup outside disposable MySQL.');
        }
        foreach (['approach_changes', 'approach_content_revisions'] as $table) {
            DB::table($table)->whereIn('approach_id', $this->approaches44)->delete();
        }
        DB::table('approaches')->whereIn('id', $this->approaches44)->delete();
        DB::table('authorization_changes')->whereIn('actor_id', $this->users44)->delete();
        foreach (['model_has_roles', 'model_has_permissions'] as $table) {
            DB::table($table)->where('model_type', User::class)->whereIn('model_id', $this->users44)->delete();
        }
        DB::table('organization_memberships')->whereIn('user_id', $this->users44)->delete();
        DB::table('users')->whereIn('id', $this->users44)->delete();
        parent::tearDown();
    }

    public function test_mysql_adapter_denies_cross_tenant_assignment_and_preserves_stale_history(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        [, , , $foreign] = $this->fixture44();
        $this->login44($actor, $organization);
        $access = app(ApproachAccess::class);
        $this->deny44(fn () => $access->show($foreign->public_id));
        $token = $access->show($approach->public_id)['edit_token'];
        $access->update($approach->public_id, ['name' => 'first'], $token);
        try {
            $access->update($approach->public_id, ['name' => 'lost'], $token);
            $this->fail('Stale metadata accepted.');
        } catch (ConflictHttpException $error) {
            $this->assertSame(409, $error->getStatusCode());
        }
        SyntheticApproachPolicy::$grants[$actor->id]['editDraft'] = [];
        $this->deny44(fn () => $access->saveDraft($approach->public_id, 'forbidden', 'none'));
        $this->assertDatabaseCount('approach_changes', 1);
        $this->assertDatabaseCount('approach_content_revisions', 0);
        $this->assertSame('first', $approach->fresh()->name);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_mysql_current_lock_rejects_revoked_membership_inside_old_snapshot(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        config(['database.connections.issue44_revoker' => config('database.connections.mysql')]);
        DB::beginTransaction();
        try {
            $this->assertTrue(OrganizationMembership::query()->where('user_id', $actor->id)->exists());
            // Structural role pivot must be removed before its restrictive membership FK.
            DB::connection('issue44_revoker')->table('model_has_roles')->where('model_id', $actor->id)->delete();
            DB::connection('issue44_revoker')->table('organization_memberships')->where('user_id', $actor->id)->delete();
            $this->assertTrue(OrganizationMembership::query()->where('user_id', $actor->id)->exists(), 'Old RR snapshot still sees membership.');
            $this->deny44(fn () => app(ApproachAccess::class)->saveDraft($approach->public_id, 'forbidden', 'none'));
            $this->assertDatabaseCount('approach_content_revisions', 0);
        } finally {
            DB::rollBack();
            DB::purge('issue44_revoker');
        }
    }

    public function test_mysql_insert_fault_rolls_back_draft_and_retry_keeps_version_one(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $this->login44($actor, $organization);
        $armed = true;
        Event::listen('eloquent.created: '.ApproachContentRevision::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('issue44 synthetic failure');
            }
        });
        try {
            app(ApproachAccess::class)->saveDraft($approach->public_id, 'rolled back', 'none');
            $this->fail('Fault must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('issue44 synthetic failure', $error->getMessage());
            $this->assertDatabaseCount('approach_content_revisions', 0);
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            $armed = false;
        }
        app(ApproachAccess::class)->saveDraft($approach->public_id, 'committed', 'none');
        $this->assertSame(1, ApproachContentRevision::sole()->version_number);
    }

    public function test_two_real_workers_with_same_draft_token_commit_one_and_reject_one_409(): void
    {
        [$actor, $organization, , $approach] = $this->fixture44();
        $workers = [$this->worker44($actor, $organization->public_id, $approach->public_id, 'first'),
            $this->worker44($actor, $organization->public_id, $approach->public_id, 'second')];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $organization->id)->lockForUpdate()->first();
            $connections = [];
            foreach ($workers as $worker) {
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'ready')), $worker->getErrorOutput());
                $connections[] = json_decode(explode("\n", trim($worker->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR)['connection'];
            }
            $this->assertCount(2, array_unique($connections));
            $deadline = microtime(true) + 6;
            do {
                $waiting = (int) DB::selectOne("SELECT COUNT(DISTINCT t.PROCESSLIST_ID) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID IN (?, ?) AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'organizations'", $connections)->total;
                if ($waiting !== 2) {
                    usleep(10000);
                }
            } while ($waiting !== 2 && microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Both independent writers must actually wait on the trusted transaction boundary.');
            DB::commit();
            $outcomes = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $outcomes[] = json_decode(explode("\n", trim($worker->getOutput()))[1], true, flags: JSON_THROW_ON_ERROR)['outcome'];
            }
            sort($outcomes);
            $this->assertSame([201, 409], $outcomes);
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($workers as $worker) {
                if ($worker->isRunning()) {
                    $worker->stop(1);
                }
            }
        }
        $this->assertDatabaseCount('approach_content_revisions', 1);
        $this->assertSame(1, ApproachContentRevision::sole()->version_number);
        $this->assertDatabaseCount('approach_changes', 0);
    }

    private function worker44(User $actor, string $organization, string $approach, string $body): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (getenv('MYSQL_TESTS_ENABLED') !== '1' || !$app->environment('testing') || $db->getDriverName() !== 'mysql'
    || $db->getDatabaseName() !== 'quality_platform_ci' || $db->selectOne('SELECT DATABASE() AS n')->n !== 'quality_platform_ci'
    || $app->configurationIsCached() || config('database.connections.mysql.url')) { throw new RuntimeException('Unsafe Issue44 worker target.'); }
require_once 'tests/Feature/Filament/ApproachFilamentIntegrationTest.php';
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$actor = App\Models\User::findOrFail($input['actor']);
config(['authorization.capabilities'=>['approaches.access'=>App\Models\Organization::class]]);
app()->bind(App\Policies\ApproachPolicy::class, Tests\Feature\Filament\SyntheticApproachPolicy::class);
Tests\Feature\Filament\SyntheticApproachPolicy::$grants[$actor->id]['editDraft'][$input['approach']] = true;
app('session.store')->put(['identity.active_guard'=>'institution','institution.organization'=>$input['organization'],'institution.password_hash'=>$actor->getAuthPassword()]);
Illuminate\Support\Facades\Auth::guard('institution')->setUser($actor);
echo json_encode(['phase'=>'ready','connection'=>(int)$db->selectOne('SELECT CONNECTION_ID() AS id')->id]).PHP_EOL;
flush();
try {
    app(App\Filament\Institution\Approaches\ApproachAccess::class)->saveDraft($input['approach'],$input['body'],'none');
    $outcome=201;
} catch (Symfony\Component\HttpKernel\Exception\ConflictHttpException $error) { $outcome=$error->getStatusCode(); }
echo json_encode(['outcome'=>$outcome]).PHP_EOL;
PHP;
        $database = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'MYSQL_TESTS_ENABLED' => '1', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => 'quality_platform_ci',
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setInput(json_encode(['actor' => $actor->id, 'organization' => $organization, 'approach' => $approach, 'body' => $body], JSON_THROW_ON_ERROR));
        $process->setTimeout(30);

        return $process;
    }
}
