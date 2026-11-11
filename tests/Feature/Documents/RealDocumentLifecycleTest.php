<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\RegisterDocumentIntake;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Models\Document;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\HarmlessDocumentSamples;
use Tests\Support\PrivateIntakeTestDisk;
use Tests\TestCase;

class RealDocumentLifecycleTest extends TestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;
    use PrivateIntakeTestDisk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentWorkspace();
        parent::tearDown();
    }

    public function test_the_existing_trusted_version_boundary_preserves_optional_detected_media_type(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $document = Document::where('public_id', $id)->firstOrFail();
            $original = DB::table('document_versions')->where('document_id', $document->id)->first();
            $stream = fopen('php://temp', 'w+b');
            fwrite($stream, HarmlessDocumentSamples::pdf());
            rewind($stream);
            $boundary = app(CreateDocumentWithVersion::class);
            try {
                $receipt = $boundary->writeBlob($f['organization']->public_id, $id, (string) Str::ulid(), $stream);
                $receipt['detected_media_type'] = 'application/pdf';
                $result = $boundary->persistVersion($document, $f['editor'], 2, 'harmless.pdf', $receipt);
                $this->assertSame('application/pdf', DB::table('document_versions')->where('public_id', $result['version_public_id'])->value('detected_media_type'));
                $this->assertNull($original->detected_media_type);
                $this->assertSame($original->sha256, DB::table('document_versions')->where('id', $original->id)->value('sha256'));
            } finally {
                fclose($stream);
            }
        });
    }

    public function test_existing_synthetic_grants_never_authorize_real_intake_or_store_any_bytes(): void
    {
        $f = $this->documentFixture();
        $disk = $this->privateDisk();
        $action = new RegisterDocumentIntake($disk, new DocumentInspectionLimits(30000000, 60000000, 100));
        $path = tempnam(sys_get_temp_dir(), 'qep68-');
        file_put_contents($path, HarmlessDocumentSamples::pdf());
        try {
            foreach (['editor', 'reader', 'unassigned'] as $name) {
                $this->loginDocumentActor($f[$name], $f['organization']);
                try {
                    $this->inDocumentFixture($f, fn () => $action->execute($f[$name],
                        ['title' => 'ملف فعلي', 'description' => 'لا يجوز استنتاج صلاحية رفعه.'],
                        new UploadedFile($path, 'sample.pdf', 'application/pdf', UPLOAD_ERR_OK, true), (string) Str::ulid()));
                    $this->fail('Synthetic grants must never authorize a real upload.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('document_intakes', 0);
                    $this->assertSame([], $disk->allFiles());
                }
            }
        } finally {
            unlink($path);
        }
    }
}
