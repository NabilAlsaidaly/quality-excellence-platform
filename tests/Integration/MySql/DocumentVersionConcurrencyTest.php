<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\ListDocuments;
use App\Actions\Documents\ReadDocumentHistory;
use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;

require_once __DIR__.'/DocumentSchemaIsolationTest.php';

class DocumentVersionConcurrencyTest extends MySqlTestCase
{
    use DatabaseMigrations, DocumentMySqlFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentFixtures();
        parent::tearDown();
    }

    public function test_two_real_processes_wait_and_append_distinct_versions_without_overwriting_v1(): void
    {
        $member = $this->documentMember();
        $first = $this->createDocumentFixture($member);
        $original = DocumentVersion::sole()->fresh()->getAttributes();
        $document = Document::sole();
        $workers = [$this->writer($member, $first['document_public_id'], 'second writer'), $this->writer($member, $first['document_public_id'], 'third writer')];
        DB::beginTransaction();
        try {
            DB::table('organizations')->where('id', $member->organization_id)->lockForUpdate()->first();
            $connections = [];
            foreach ($workers as $worker) {
                $worker->start();
                $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'ready')), $worker->getErrorOutput());
                $ready = json_decode(explode("\n", trim($worker->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR);
                $connections[] = $ready['connection'];
            }
            $this->assertCount(2, array_unique($connections));
            $deadline = microtime(true) + 6;
            do {
                $waiting = (int) DB::selectOne("SELECT COUNT(DISTINCT t.PROCESSLIST_ID) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID IN (?, ?) AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'organizations'", $connections)->total;
                if ($waiting !== 2) {
                    usleep(10000);
                }
            } while ($waiting !== 2 && microtime(true) < $deadline);
            $this->assertSame(2, $waiting, 'Observe both independent InnoDB connections actually waiting.');
            DB::commit();
            $numbers = [];
            foreach ($workers as $worker) {
                $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
                $lines = explode("\n", trim($worker->getOutput()));
                $numbers[] = json_decode($lines[1], true, flags: JSON_THROW_ON_ERROR)['version_number'];
            }
            sort($numbers);
            $this->assertSame([2, 3], $numbers);
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
        $this->assertSame([1, 2, 3], $document->versions()->orderBy('version_number')->pluck('version_number')->all());
        $this->assertSame($original, DocumentVersion::query()->where('version_number', 1)->firstOrFail()->getAttributes());
        $this->assertCount(3, Storage::disk('local')->allFiles());
        $this->assertSame('historical v1', Storage::disk('local')->get($original['storage_key']));
        foreach ($document->versions()->get() as $version) {
            $integrity = $this->trustedDocument($member, fn () => app(ReadDocumentHistory::class)->verify($member->user, $document->public_id, $version->public_id));
            $this->assertSame($version->sha256, $integrity['sha256']);
        }
    }

    public function test_locked_current_membership_read_denies_despite_old_mysql_snapshot(): void
    {
        $member = $this->documentMember();
        $first = $this->createDocumentFixture($member);
        config(['database.connections.document_revoker' => config('database.connections.mysql')]);
        try {
            $this->trustedDocument($member, function () use ($member, $first): void {
                DB::beginTransaction();
                try {
                    $this->assertNotNull(DB::table('organization_memberships')->where('id', $member->id)->first());
                    DB::connection('document_revoker')->table('organization_memberships')->where('id', $member->id)->delete();
                    $this->assertTrue(app(TenantContext::class)->verifiedFor($member->user), 'The old consistent snapshot still contains membership.');
                    foreach ([fn () => app(ListDocuments::class)->execute($member->user),
                        fn () => app(ReadDocumentHistory::class)->execute($member->user, $first['document_public_id']),
                        fn () => app(ReadDocumentHistory::class)->verify($member->user, $first['document_public_id'], $first['version_public_id'])] as $operation) {
                        try {
                            $operation();
                            $this->fail('Current locking membership revalidation must deny.');
                        } catch (AuthorizationException) {
                            $this->assertDatabaseCount('document_versions', 1);
                        }
                    }
                } finally {
                    DB::rollBack();
                }
            });
        } finally {
            DB::purge('document_revoker');
        }
    }

    private function writer(OrganizationMembership $member, string $document, string $bytes): Process
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection();
if (getenv('MYSQL_TESTS_ENABLED') !== '1' || !$app->environment('testing') || $db->getDriverName() !== 'mysql'
    || $db->getDatabaseName() !== 'quality_platform_ci' || $db->selectOne('SELECT DATABASE() AS n')->n !== 'quality_platform_ci'
    || $app->configurationIsCached() || config('database.connections.mysql.url')) { throw new RuntimeException('Unsafe document worker target.'); }
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
$root = realpath($input['root']);
$allowed = realpath(storage_path('framework/testing/disks'));
if (!$root || !$allowed || !str_starts_with(str_replace('\\','/',$root).'/',str_replace('\\','/',$allowed).'/')) { throw new RuntimeException('Unsafe document worker storage.'); }
config(['filesystems.disks.local.root'=>$root]);
$actor = App\Models\User::findOrFail($input['actor']);
echo json_encode(['phase'=>'ready','connection'=>(int)$db->selectOne('SELECT CONNECTION_ID() AS id')->id]).PHP_EOL;
flush();
$stream = fopen('php://temp','w+b');
fwrite($stream,$input['bytes']);
rewind($stream);
try {
    $result = app(App\Authorization\TenantContext::class)->run($actor,$input['organization'],
        fn()=>app(App\Actions\Documents\AppendDocumentVersion::class)->execute($actor,$input['document'],['original_filename'=>'synthetic.bin'],$stream));
    echo json_encode(['phase'=>'committed','version_number'=>$result['version_number']]).PHP_EOL;
} finally { fclose($stream); }
PHP;
        $database = config('database.connections.mysql');
        $process = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), '-r', $code], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'MYSQL_TESTS_ENABLED' => '1', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => 'quality_platform_ci',
            'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $process->setInput(json_encode(['actor' => $member->user_id, 'organization' => $member->organization_id,
            'document' => $document, 'bytes' => $bytes, 'root' => Storage::disk('local')->path('')], JSON_THROW_ON_ERROR));
        $process->setTimeout(25);

        return $process;
    }
}
