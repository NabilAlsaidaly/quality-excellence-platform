<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\DecideDemoDocumentAvailability;
use App\Actions\Documents\DocumentFileWorkspace;
use App\Actions\Documents\DocumentWorkspace;
use App\Actions\Documents\DownloadDemoDocumentVersion;
use App\Actions\Documents\ManageDocumentFileOperations;
use App\Actions\Documents\RegisterDocumentIntake;
use App\Domain\Documents\Intake\DocumentInspectionLimits;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\HarmlessDocumentSamples;
use Tests\Support\PrivateIntakeTestDisk;
use Tests\TestCase;

class DocumentFileOperationAuthorizationTest extends TestCase
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
                foreach ($this->documentWorkspaceFixtures as $f) {
                    $scope = fn ($table) => DB::table($table)->where('organization_id', $f['organization']->id)->where('fixture_id', $f['proof']['fixture_id']);
                    $scope('document_demo_availability_decisions')->delete();
                    $scope('document_file_operation_changes')->delete();
                    $scope('document_file_operation_assignments')->delete();
                    $scope('document_intake_events')->delete();
                    $scope('document_intakes')->delete();
                }
                $this->cleanupDocumentWorkspace();
            }
        } finally {
            parent::tearDown();
        }
    }

    protected function permittedUpload(array $f): array
    {
        $scope = ['document_public_id' => strtolower((string) Str::ulid()), 'version_public_id' => strtolower((string) Str::ulid()),
            'public_id' => strtolower((string) Str::ulid())];
        $this->loginDocumentActor($f['issuer'], $f['organization']);
        $scope['public_id'] = $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)
            ->issue($f['issuer'], $f['editor'], 'upload_real', $scope['document_public_id'], $scope['version_public_id']));

        return $scope;
    }

    public function test_actual_policy_honors_an_explicit_finite_version_scoped_upload_without_a_clean_claim(): void
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $permit = $this->permittedUpload($f);
        $this->loginDocumentActor($f['editor'], $f['organization']);
        $file = tempnam(sys_get_temp_dir(), 'closure-pdf-');
        $bytes = HarmlessDocumentSamples::pdf();
        file_put_contents($file, $bytes);
        try {
            $result = $this->inDocumentFixture($f, function () use ($f, $permit, $file): array {
                $action = new RegisterDocumentIntake(Storage::disk('local'), new DocumentInspectionLimits(30000000, 60000000, 100));
                try {
                    return $action->execute($f['editor'], ['title' => 'وثيقة فعلية بصلاحية مستقلة', 'description' => 'رفع حقيقي دون ادعاء فحص.'],
                        new UploadedFile($file, 'harmless.pdf', 'application/pdf', UPLOAD_ERR_OK, true), (string) Str::ulid(),
                        $permit['document_public_id'], 'none', $permit['public_id']);
                } catch (AuthorizationException) {
                    $this->fail('Actual DocumentPolicy denied the explicit persisted version-scoped upload permit.');
                }
            });
            $this->assertSame($permit['document_public_id'], $result['document_public_id']);
            $this->assertSame($permit['version_public_id'], $result['version_public_id']);
            $version = DB::table('document_versions')->where('public_id', $result['version_public_id'])->sole();
            $this->assertSame(strlen($bytes), $version->byte_size);
            $this->assertSame(hash('sha256', $bytes), $version->sha256);
            $this->assertSame($bytes, Storage::disk('local')->get($version->storage_key));
            $this->assertDatabaseHas('document_intakes', ['version_id' => $version->id, 'state' => 'pending', 'scan_result' => null]);
            $this->assertDatabaseCount('document_intake_events', 1);
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'scan_clean']);
            $this->assertFalse($this->inDocumentFixture($f, fn () => app(DocumentWorkspace::class)->can('view', $result['document_public_id'])),
                'Uploader/creator status must not create a metadata grant.');
            $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
        } finally {
            unlink($file);
        }
    }

    public function test_issuer_grants_are_exact_finite_nonrenewing_and_revocation_denies_before_storage(): void
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $permit = $this->permittedUpload($f);
        $before = DB::table('document_file_operation_assignments')->where('public_id', $permit['public_id'])->sole();
        $this->travel(1)->hour();
        $this->loginDocumentActor($f['issuer'], $f['organization']);
        $again = $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)
            ->issue($f['issuer'], $f['editor'], 'upload_real', $permit['document_public_id'], $permit['version_public_id']));
        $this->assertSame($permit['public_id'], $again);
        $this->assertSame($before->expires_at, DB::table('document_file_operation_assignments')->where('public_id', $again)->value('expires_at'));
        $this->assertDatabaseCount('document_file_operation_changes', 1);
        $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->revoke($f['issuer'], $again));
        $this->loginDocumentActor($f['editor'], $f['organization']);
        $file = tempnam(sys_get_temp_dir(), 'closure-denied-');
        file_put_contents($file, HarmlessDocumentSamples::pdf());
        try {
            try {
                $this->inDocumentFixture($f, fn () => app(DocumentFileWorkspace::class)->upload(
                    new UploadedFile($file, 'denied.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
                    ['title' => 'ممنوع', 'description' => 'صلاحية مسحوبة'], (string) Str::ulid(), $again));
                $this->fail('Revoked exact-version permit must fail closed.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('document_intakes', 0);
                $this->assertDatabaseCount('document_versions', 0);
                $this->assertSame([], Storage::disk('local')->allFiles());
                $this->assertDatabaseHas('document_file_operation_changes', ['event' => 'revoked']);
                $this->assertDatabaseHas('document_file_operation_changes', ['event' => 'access_denied']);
            }
        } finally {
            unlink($file);
            $this->travelBack();
        }
    }

    public function test_foreign_recipient_cannot_receive_a_file_grant_and_editor_cannot_issue_one(): void
    {
        $f = $this->documentFixture();
        $foreign = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        foreach ([[$f['issuer'], $foreign['editor']], [$f['editor'], $f['reader']]] as [$actor, $recipient]) {
            $this->loginDocumentActor($actor, $f['organization']);
            try {
                $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->issue($actor, $recipient,
                    'upload_real', (string) Str::ulid(), (string) Str::ulid()));
                $this->fail('Only the named issuer may grant within its actual institution.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('document_file_operation_assignments', 0);
                $this->assertDatabaseCount('document_file_operation_changes', 0);
            }
        }
    }

    public function test_independent_demo_availability_downloads_exact_bytes_without_a_clean_claim(): void
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $f['proof']['demo_unscanned_exception'] = true;
        $f['proof']['demo_file_receipts'] = [hash('sha256', HarmlessDocumentSamples::pdf()) => [
            'byte_size' => strlen(HarmlessDocumentSamples::pdf()), 'media_type' => 'application/pdf']];
        $permit = $this->permittedUpload($f);
        foreach ([[$f['reader'], 'release_demo_unscanned'], [$f['reader'], 'download_demo_unscanned'], [$f['editor'], 'download_demo_unscanned']] as [$recipient, $op]) {
            $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->issue($f['issuer'], $recipient, $op,
                $permit['document_public_id'], $permit['version_public_id']));
        }
        $file = tempnam(sys_get_temp_dir(), 'closure-available-');
        $bytes = HarmlessDocumentSamples::pdf();
        file_put_contents($file, $bytes);
        try {
            $this->loginDocumentActor($f['editor'], $f['organization']);
            $result = $this->inDocumentFixture($f, fn () => app(DocumentFileWorkspace::class)->upload(
                new UploadedFile($file, 'owner-demo.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
                ['title' => 'دليل اصطناعي للعرض', 'description' => 'لم يُفحص أمنيًا.'], (string) Str::ulid(), $permit['public_id']));
            $this->loginDocumentActor($f['reader'], $f['organization']);
            $decision = $this->inDocumentFixture($f, fn () => app(DecideDemoDocumentAvailability::class)->execute(
                $f['reader'], $result['intake_public_id'], true, (string) Str::ulid(), 'ملف اصطناعي معلوم المصدر للعرض المحلي فقط.'));
            $this->assertSame('available_demo_unscanned', $decision['state']);
            $response = $this->inDocumentFixture($f, fn () => app(DownloadDemoDocumentVersion::class)->execute(
                $f['reader'], $result['document_public_id'], $result['version_public_id']));
            ob_start();
            try {
                $response->sendContent();
                $downloaded = ob_get_contents();
            } finally {
                ob_end_clean();
            }
            $this->assertSame($bytes, $downloaded);
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $downloaded));
            $this->assertSame((string) strlen($bytes), $response->headers->get('Content-Length'), 'The browser must receive the exact verified attachment length.');
            $this->assertStringContainsString('attachment;', $response->headers->get('Content-Disposition'));
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
            $this->assertDatabaseHas('document_intakes', ['public_id' => $result['intake_public_id'], 'state' => 'pending', 'scan_result' => null]);
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'scan_clean']);
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'released']);
        } finally {
            unlink($file);
        }
    }

    private function pendingDemoVersion(string $variant = ''): array
    {
        $f = $this->documentFixture();
        $f['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $f['proof']['demo_unscanned_exception'] = true;
        $f['proof']['demo_file_receipts'] = [hash('sha256', HarmlessDocumentSamples::pdf()) => [
            'byte_size' => strlen(HarmlessDocumentSamples::pdf()), 'media_type' => 'application/pdf']];
        $permit = $this->permittedUpload($f);
        $grants = [];
        foreach (['release_demo_unscanned', 'download_demo_unscanned'] as $op) {
            $grants[$op] = $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->issue($f['issuer'], $f['reader'], $op,
                $permit['document_public_id'], $permit['version_public_id']));
        }
        $this->loginDocumentActor($f['editor'], $f['organization']);
        $file = tempnam(sys_get_temp_dir(), 'closure-negative-');
        file_put_contents($file, HarmlessDocumentSamples::pdf().$variant);
        try {
            $result = $this->inDocumentFixture($f, fn () => app(DocumentFileWorkspace::class)->upload(
                new UploadedFile($file, 'harmless-negative.pdf', 'application/pdf', UPLOAD_ERR_OK, true),
                ['title' => 'نسخة للتحقق السلبي', 'description' => 'ملف اصطناعي غير مفحوص.'], (string) Str::ulid(), $permit['public_id']));
        } finally {
            unlink($file);
        }

        return [$f, $result, $grants];
    }

    public function test_unavailable_version_and_author_self_release_fail_closed_with_audit(): void
    {
        [$f, $result] = $this->pendingDemoVersion();
        try {
            $this->inDocumentFixture($f, fn () => app(DecideDemoDocumentAvailability::class)->execute(
                $f['editor'], $result['intake_public_id'], true, (string) Str::ulid(), 'لا يجوز اعتماد الإتاحة ذاتيًا.'));
            $this->fail('Uploader must not release its own version.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('document_demo_availability_decisions', 0);
        }
        $this->loginDocumentActor($f['reader'], $f['organization']);
        try {
            $this->inDocumentFixture($f, fn () => app(DownloadDemoDocumentVersion::class)->execute(
                $f['reader'], $result['document_public_id'], $result['version_public_id']));
            $this->fail('An explicit download permit alone must not release a version.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'downloaded']);
            $this->assertDatabaseHas('document_file_operation_changes', ['event' => 'access_denied']);
        }
    }

    public function test_expired_and_revoked_grants_deny_existing_session_downloads_immediately(): void
    {
        [$f, $result, $grants] = $this->pendingDemoVersion();
        $this->loginDocumentActor($f['reader'], $f['organization']);
        $this->inDocumentFixture($f, fn () => app(DecideDemoDocumentAvailability::class)->execute(
            $f['reader'], $result['intake_public_id'], true, (string) Str::ulid(), 'عرض محلي محدود.'));
        $this->travel(24)->hours();
        try {
            try {
                $this->inDocumentFixture($f, fn () => app(DownloadDemoDocumentVersion::class)->execute(
                    $f['reader'], $result['document_public_id'], $result['version_public_id']));
                $this->fail('Exact expiry must deny the still-authenticated reader.');
            } catch (AuthorizationException) {
                $this->assertDatabaseMissing('document_intake_events', ['event' => 'downloaded']);
                $this->assertDatabaseHas('document_file_operation_changes', ['event' => 'expiration_observed']);
            }
        } finally {
            $this->travelBack();
        }
        $this->loginDocumentActor($f['issuer'], $f['organization']);
        $this->inDocumentFixture($f, fn () => app(ManageDocumentFileOperations::class)->revoke($f['issuer'], $grants['download_demo_unscanned']));
        $this->loginDocumentActor($f['reader'], $f['organization']);
        try {
            $this->inDocumentFixture($f, fn () => app(DownloadDemoDocumentVersion::class)->execute(
                $f['reader'], $result['document_public_id'], $result['version_public_id']));
            $this->fail('Revoked download grant must deny the existing session.');
        } catch (AuthorizationException) {
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'downloaded']);
            $this->assertDatabaseCount('document_demo_availability_decisions', 1);
        }
    }

    public function test_started_or_failed_scans_can_never_use_the_unscanned_exception(): void
    {
        [$f, $result] = $this->pendingDemoVersion();
        $this->loginDocumentActor($f['reader'], $f['organization']);
        foreach ([['scanning', null], ['suspect', 'suspect'], ['failed', 'unavailable']] as [$state, $scan]) {
            // Deliberate hostile persisted states in this disposable negative fixture;
            // this is not scanner acceptance or a forged clean-scan receipt.
            DB::table('document_intakes')->where('public_id', $result['intake_public_id'])->update(['state' => $state, 'scan_result' => $scan,
                'lease_hash' => $state === 'scanning' ? hash('sha256', 'negative-owned-lease') : null,
                'lease_expires_at' => $state === 'scanning' ? now('UTC')->addMinute()->format('Y-m-d H:i:s') : null]);
            try {
                $this->inDocumentFixture($f, fn () => app(DecideDemoDocumentAvailability::class)->execute(
                    $f['reader'], $result['intake_public_id'], true, (string) Str::ulid(), 'يجب رفض هذا الطلب.'));
                $this->fail('Unsafe/started scan cannot fall back to unscanned availability.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('document_demo_availability_decisions', 0);
                $this->assertDatabaseMissing('document_intake_events', ['event' => 'scan_clean']);
            }
        }
    }

    public function test_an_unlisted_file_cannot_be_made_available_even_by_the_independent_granted_reviewer(): void
    {
        [$f, $result] = $this->pendingDemoVersion("\n");
        $this->loginDocumentActor($f['reader'], $f['organization']);
        try {
            $this->inDocumentFixture($f, fn () => app(DecideDemoDocumentAvailability::class)->execute(
                $f['reader'], $result['intake_public_id'], true, (string) Str::ulid(), 'قرار المراجع لا يتجاوز قيد العينات المعلومة.'));
            $this->fail('Independent release authority cannot authorize arbitrary unscanned bytes.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('document_demo_availability_decisions', 0);
            $this->assertDatabaseHas('document_intakes', ['public_id' => $result['intake_public_id'], 'state' => 'pending', 'scan_result' => null]);
            $this->assertDatabaseMissing('document_intake_events', ['event' => 'scan_clean']);
        }
    }
}
