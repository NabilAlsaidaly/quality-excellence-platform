<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\DocumentFileWorkspace;
use App\Actions\Documents\ManageDocumentFileOperations;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\HarmlessDocumentSamples;
use Tests\Support\PrivateIntakeTestDisk;
use Tests\TestCase;

class ClosureFileAtomicityTest extends TestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures, PrivateIntakeTestDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
        Storage::set('local', $this->privateDisk());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->documentWorkspaceFixtures as $fixture) {
                    foreach (['document_file_operation_changes', 'document_file_operation_assignments', 'document_intake_events', 'document_intakes'] as $table) {
                        DB::table($table)->where('organization_id', $fixture['organization']->id)->where('fixture_id', $fixture['proof']['fixture_id'])->delete();
                    }
                }
                $this->cleanupDocumentWorkspace();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_failed_intake_event_rolls_back_document_version_and_permit_binding_and_compensates_only_owned_bytes(): void
    {
        $fixture = $this->documentFixture();
        $fixture['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $this->loginDocumentActor($fixture['issuer'], $fixture['organization']);
        $grant = $this->inDocumentFixture($fixture, fn () => app(ManageDocumentFileOperations::class)->issue($fixture['issuer'], $fixture['editor'],
            'upload_real', strtolower((string) Str::ulid()), strtolower((string) Str::ulid())));
        $this->loginDocumentActor($fixture['editor'], $fixture['organization']);
        $file = tempnam(sys_get_temp_dir(), 'closure-atomic-');
        $bytes = HarmlessDocumentSamples::pdf();
        file_put_contents($file, $bytes);
        $key = (string) Str::ulid();
        $upload = fn () => $this->inDocumentFixture($fixture, fn () => app(DocumentFileWorkspace::class)->upload(
            new UploadedFile($file, 'atomic.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
            ['title' => 'وثيقة ذرية اختبارية', 'description' => 'بايتات اصطناعية دون ادعاء فحص.'], $key, $grant));
        $armed = true;
        DB::connection()->beforeExecuting(function (string $sql) use (&$armed): void {
            if ($armed && preg_match('/\Ainsert into ["`]?document_intake_events["`]?\s/i', $sql)) {
                throw new RuntimeException('Injected intake audit persistence failure.');
            }
        });
        try {
            try {
                $upload();
                $this->fail('The injected persistence failure must remain visible.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected intake audit persistence failure.', $error->getMessage());
            } finally {
                $armed = false;
            }
            foreach (['documents', 'document_versions', 'document_intakes', 'document_intake_events'] as $table) {
                $this->assertDatabaseCount($table, 0);
            }
            $this->assertDatabaseHas('document_file_operation_assignments', ['public_id' => $grant, 'document_id' => null, 'version_id' => null]);
            $this->assertSame([], Storage::disk('local')->allFiles());
            $result = $upload();
            $version = DB::table('document_versions')->where('public_id', $result['version_public_id'])->sole();
            $this->assertSame($bytes, Storage::disk('local')->get($version->storage_key));
            $this->assertDatabaseHas('document_file_operation_assignments', ['public_id' => $grant, 'document_id' => $version->document_id, 'version_id' => $version->id]);
            $durableFiles = Storage::disk('local')->allFiles();
            $this->assertSame([$version->storage_key, $version->storage_key.'.json'], $durableFiles);
            $receiptBytes = Storage::disk('local')->get($version->storage_key.'.json');
            $again = $upload();
            $this->assertTrue($result['created']);
            $this->assertFalse($again['created']);
            $this->assertSame(array_diff_key($result, ['created' => true]), array_diff_key($again, ['created' => true]),
                'Same-key retry must return the exact original resource identities.');
            $this->assertDatabaseCount('document_versions', 1);
            $this->assertDatabaseCount('document_intakes', 1);
            $this->assertDatabaseCount('document_intake_events', 1);
            $this->assertSame($durableFiles, Storage::disk('local')->allFiles(), 'Duplicate compensation must retain the first owned durable bytes and receipt.');
            $this->assertSame($receiptBytes, Storage::disk('local')->get($version->storage_key.'.json'));
            $this->assertSame(hash('sha256', $bytes), hash('sha256', Storage::disk('local')->get($version->storage_key)));
        } finally {
            $armed = false;
            unlink($file);
        }
    }
}
