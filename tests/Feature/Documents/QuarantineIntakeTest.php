<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\QuarantineTestFile;
use App\Authorization\TenantContext;
use App\Domain\Documents\Intake\IntakeRules;
use App\Domain\Documents\Intake\ScanResult;
use App\Models\OrganizationMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** No user HTTP intake, release, DocumentVersion promotion or AV configuration. */
class QuarantineIntakeTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jmXcAAAAASUVORK5CYII=';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('quarantine-tests');
    }

    private function rules(): IntakeRules
    {
        // Fixture parameters, not a ratified upload policy or a runtime default.
        return new IntakeRules(1024, ['png' => ['image/png']]);
    }

    public static function scanStates(): array
    {
        return [['clean'], ['suspect'], ['unavailable']];
    }

    #[DataProvider('scanStates')]
    public function test_bytes_and_scan_receipt_stay_quarantined_without_document_or_public_release(string $result): void
    {
        $member = OrganizationMembership::factory()->create();
        $bytes = base64_decode(self::PNG, true);
        $scan = function ($stream) use ($bytes, $result): ScanResult {
            $this->assertSame($bytes, stream_get_contents($stream));

            return ScanResult::from($result);
        };
        $receipt = app(TenantContext::class)->run($member->user, $member->organization_id,
            fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('دليل.png', $bytes), $this->rules(), Storage::disk('quarantine-tests'), $scan));
        $this->assertSame($result, $receipt['scan_result']);
        $this->assertFalse($receipt['servable']);
        $this->assertSame(hash('sha256', $bytes), $receipt['sha256']);
        $this->assertArrayNotHasKey('storage_key', $receipt);
        $this->assertArrayNotHasKey('path', $receipt);
        $files = Storage::disk('quarantine-tests')->allFiles();
        $this->assertCount(2, $files);
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        foreach ($files as $file) {
            $this->assertStringStartsWith('quarantine/'.strtolower($member->organization->public_id).'/', $file);
            $this->assertFileDoesNotExist(public_path('storage/'.$file));
        }
    }

    public function test_spoofed_mime_oversize_and_executable_double_extension_do_not_create_files(): void
    {
        $member = OrganizationMembership::factory()->create();
        foreach ([UploadedFile::fake()->createWithContent('wrong.png', '<?php harmless test ?>'),
            UploadedFile::fake()->createWithContent('large.png', base64_decode(self::PNG).str_repeat('x', 1024)),
            UploadedFile::fake()->createWithContent('hidden.php.png', base64_decode(self::PNG))] as $file) {
            try {
                app(TenantContext::class)->run($member->user, $member->organization_id,
                    fn () => app(QuarantineTestFile::class)->execute($member->user, $file, $this->rules(), Storage::disk('quarantine-tests'), fn () => ScanResult::Clean));
                $this->fail('Unsafe technical fixture accepted.');
            } catch (ValidationException) {
                $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
            }
        }
    }

    public function test_scanner_exception_is_recorded_as_unavailable_and_never_releases_bytes(): void
    {
        $member = OrganizationMembership::factory()->create();
        $receipt = app(TenantContext::class)->run($member->user, $member->organization_id,
            fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::disk('quarantine-tests'), fn () => throw new \RuntimeException('fake scanner offline')));
        $this->assertSame('unavailable', $receipt['scan_result']);
        $this->assertFalse($receipt['servable']);
        $this->assertCount(2, Storage::disk('quarantine-tests')->allFiles());
    }

    public function test_missing_context_and_normal_environments_cannot_use_the_fixture_intake(): void
    {
        $member = OrganizationMembership::factory()->create();
        try {
            app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::disk('quarantine-tests'), fn () => ScanResult::Clean);
            $this->fail('Unverified caller accepted.');
        } catch (AuthorizationException) {
            $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
        }
        app()->instance('env', 'local');
        try {
            $this->expectException(LogicException::class);
            app(TenantContext::class)->run($member->user, $member->organization_id,
                fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::disk('quarantine-tests'), fn () => ScanResult::Clean));
        } finally {
            app()->instance('env', 'testing');
        }
    }

    public function test_scanner_side_tampering_cannot_leave_a_success_receipt_or_file(): void
    {
        $member = OrganizationMembership::factory()->create();
        try {
            app(TenantContext::class)->run($member->user, $member->organization_id,
                fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::disk('quarantine-tests'), function (): ScanResult {
                    $key = Storage::disk('quarantine-tests')->allFiles()[0];
                    Storage::disk('quarantine-tests')->put($key, 'corrupted during test scan');

                    return ScanResult::Clean;
                }));
            $this->fail('Changed scan target accepted.');
        } catch (\RuntimeException $error) {
            $this->assertSame('تغيرت بيانات الحجر أثناء الفحص.', $error->getMessage());
            $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
        }
    }

    public function test_membership_lost_during_scan_compensates_only_the_owned_attempt(): void
    {
        $member = OrganizationMembership::factory()->create();
        try {
            app(TenantContext::class)->run($member->user, $member->organization_id,
                fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::disk('quarantine-tests'), function () use ($member): ScanResult {
                    $member->delete();

                    return ScanResult::Clean;
                }));
            $this->fail('Revoked actor completed quarantine persistence.');
        } catch (AuthorizationException) {
            $this->assertSame([], Storage::disk('quarantine-tests')->allFiles());
            $this->assertDatabaseCount('documents', 0);
        }
    }

    public function test_normal_private_disk_is_not_an_isolated_intake_fixture(): void
    {
        $member = OrganizationMembership::factory()->create();
        $this->expectException(LogicException::class);
        app(TenantContext::class)->run($member->user, $member->organization_id,
            fn () => app(QuarantineTestFile::class)->execute($member->user, UploadedFile::fake()->createWithContent('sample.png', base64_decode(self::PNG)), $this->rules(), Storage::build(['driver' => 'local', 'root' => public_path()]), fn () => ScanResult::Clean));
    }
}
