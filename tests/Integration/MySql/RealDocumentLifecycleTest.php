<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\RegisterDocumentIntake;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Models\Document;
use App\Models\DocumentIntake;
use App\Services\Documents\InspectDocumentFile;
use App\Services\Documents\QuarantinedDocumentStore;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\HarmlessDocumentSamples;
use Tests\Support\MySqlTestCase;
use Tests\Support\PrivateIntakeTestDisk;

/** Actual bytes/schema fixtures; never claims real scanner, human release or new Policy acceptance. */
class RealDocumentLifecycleTest extends MySqlTestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures, PrivateIntakeTestDisk;

    private FilesystemAdapter $privateStorage;

    private array $receipts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
        $this->privateStorage = $this->privateDisk();
        Storage::set('local', $this->privateStorage);
    }

    protected function tearDown(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($this->documentWorkspaceFixtures as $f) {
            foreach (['document_intake_events', 'document_intakes'] as $table) {
                DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id'])->delete();
            }
        }
        foreach ($this->receipts as $receipt) {
            $this->privateStorage->delete($receipt->storageKey.'.json');
        }
        $this->cleanupDocumentWorkspace();
        parent::tearDown();
    }

    private function fixture(): array
    {
        $f = $this->documentFixture();

        return $this->inDocumentFixture($f, function () use ($f): array {
            $documentId = $this->createWorkspaceDocument($f);
            $document = Document::where('public_id', $documentId)->firstOrFail();
            $path = tempnam(sys_get_temp_dir(), 'qep68-');
            file_put_contents($path, HarmlessDocumentSamples::pdf());
            try {
                $store = new QuarantinedDocumentStore($this->privateStorage, new InspectDocumentFile);
                $receipt = $store->store($f['organization']->public_id,
                    new UploadedFile($path, 'actual.pdf', 'application/pdf', UPLOAD_ERR_OK, true), new DocumentInspectionLimits(30000000, 60000000, 100));
            } finally {
                unlink($path);
            }
            $this->receipts[] = $receipt;
            $result = app(CreateDocumentWithVersion::class)->persistVersion($document, $f['editor'], 2, 'actual.pdf',
                ['version_public_id' => (string) Str::ulid(), 'storage_key' => $receipt->storageKey,
                    'sha256' => $receipt->inspection->sha256, 'byte_size' => $receipt->inspection->byteSize, 'detected_media_type' => $receipt->inspection->mediaType]);
            $version = DB::table('document_versions')->where('public_id', $result['version_public_id'])->first();
            $id = DB::table('document_intakes')->insertGetId(['public_id' => $receipt->intakePublicId,
                'organization_id' => $f['organization']->id, 'fixture_id' => $f['proof']['fixture_id'], 'document_id' => $document->id,
                'version_id' => $version->id, 'actor_id' => $f['editor']->id, 'request_key' => (string) Str::ulid(),
                'request_hash' => hash('sha256', 'relational-fixture'), 'state' => 'pending', 'generation' => 1,
                'inspected_media_type' => $receipt->inspection->mediaType, 'limits_json' => '{"maximum_bytes":30000000}',
                'created_at' => RegisterDocumentIntake::timestamp(), 'updated_at' => RegisterDocumentIntake::timestamp()]);
            $intake = DB::table('document_intakes')->where('id', $id)->first();

            return $f + compact('document', 'version', 'receipt', 'intake');
        });
    }

    private function reject(callable $operation, int $code = 3819): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Invalid real-document persistence SQL accepted.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_real_original_bytes_digest_media_type_and_prior_version_remain_immutable(): void
    {
        $f = $this->fixture();
        $this->assertSame(HarmlessDocumentSamples::pdf(), $this->privateStorage->get($f['version']->storage_key));
        $this->assertSame(hash('sha256', HarmlessDocumentSamples::pdf()), $f['version']->sha256);
        $this->assertSame('application/pdf', $f['version']->detected_media_type);
        $this->assertSame(2, DB::table('document_versions')->where('document_id', $f['document']->id)->count());
        $this->assertNull(DB::table('document_versions')->where('document_id', $f['document']->id)->where('version_number', 1)->value('detected_media_type'));
        $model = DocumentIntake::findOrFail($f['intake']->id);
        try {
            $model->forceFill(['state' => 'clean'])->save();
            $this->fail('ORM writes cannot bypass processing CAS Actions.');
        } catch (\LogicException) {
            $this->assertSame('pending', DB::table('document_intakes')->where('id', $model->id)->value('state'));
        }
    }

    public function test_invalid_scan_release_lease_classification_and_generation_tuples_are_rejected(): void
    {
        $f = $this->fixture();
        foreach ([['state' => 'scanning'], ['state' => 'clean'], ['state' => 'failed', 'scan_result' => 'clean'],
            ['state' => 'released', 'scan_result' => 'clean'], ['generation' => 0], ['classification' => 'OPERATIONAL'],
            ['limits_json' => '[]'], ['fixture_id' => str_repeat('G', 32)]] as $changes) {
            $this->reject(fn () => DB::table('document_intakes')->where('id', $f['intake']->id)->update($changes));
        }
        $this->reject(fn () => DB::table('document_intakes')->where('id', $f['intake']->id)->update([
            'state' => 'released', 'scan_result' => 'clean', 'released_at' => RegisterDocumentIntake::timestamp(),
            'release_actor_id' => $f['editor']->id, 'release_authority_ref' => (string) Str::ulid()]));
    }

    public function test_cross_tenant_actor_and_version_and_duplicate_intake_are_rejected(): void
    {
        $f = $this->fixture();
        $other = $this->fixture();
        $this->reject(fn () => DB::table('document_intakes')->where('id', $f['intake']->id)->update(['actor_id' => $other['editor']->id]), 1452);
        $this->reject(fn () => DB::table('document_intakes')->where('id', $f['intake']->id)->update(['version_id' => $other['version']->id]), 1452);
        $copy = (array) $f['intake'];
        unset($copy['id']);
        $copy['public_id'] = (string) Str::ulid();
        $copy['request_key'] = (string) Str::ulid();
        $this->reject(fn () => DB::table('document_intakes')->insert($copy), 1062);
    }

    public function test_a_clean_state_without_verified_scan_evidence_never_satisfies_release_checks(): void
    {
        $f = $this->fixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            DB::beginTransaction();
            try {
                DB::table('document_intakes')->where('id', $f['intake']->id)->update(['state' => 'clean', 'scan_result' => 'clean']);
                try {
                    RegisterDocumentIntake::assertCleanScan(DB::table('document_intakes')->where('id', $f['intake']->id)->first());
                    $this->fail('A database label is not actual clean engine evidence.');
                } catch (AuthorizationException) {
                    $this->assertSame(0, DB::table('document_intake_events')->where('event', 'released')->count());
                }
            } finally {
                DB::rollBack();
            }
        });
    }
}
