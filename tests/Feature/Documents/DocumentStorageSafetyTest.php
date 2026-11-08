<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocumentWithVersion;
use App\Actions\Documents\ReadDocumentHistory;
use App\Models\DocumentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

require_once __DIR__.'/DocumentManagementTest.php';

class DocumentStorageSafetyTest extends TestCase
{
    use DatabaseMigrations, DocumentFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocuments();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocuments();
        parent::tearDown();
    }

    public function test_paths_uploaded_file_objects_empty_and_nonfixture_streams_are_rejected(): void
    {
        $member = $this->member();
        foreach (['/private/file.pdf', new \stdClass, null, $this->stream('')] as $input) {
            try {
                $this->trusted($member, fn () => app(CreateDocumentWithVersion::class)->execute($member->user, $this->data(), $input));
                $this->fail('Only nonempty synthetic streams are accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('documents', 0);
            } finally {
                if (is_resource($input)) {
                    fclose($input);
                }
            }
        }
        foreach (['../escape.bin', 'folder/file.bin', 'folder\\file.bin', "bad\0.bin"] as $filename) {
            try {
                $this->createDocument($member, 'bytes', ['original_filename' => $filename]);
                $this->fail('A filename must never become a path.');
            } catch (ValidationException) {
                $this->assertSame([], Storage::disk('local')->allFiles());
            }
        }
    }

    public function test_private_root_generated_paths_and_inaccessible_urls_do_not_expose_bytes(): void
    {
        $member = $this->member();
        $this->createDocument($member, 'PRIVATE ORIGINAL BYTES', ['original_filename' => '<img src=x onerror=alert(1)>.bin']);
        $version = DocumentVersion::sole();
        $this->assertSame('local', $version->storage_disk);
        $this->assertMatchesRegularExpression('#^documents/[a-z0-9]{26}/[a-z0-9]{26}/[a-z0-9]{26}/[a-f0-9]{64}\.blob$#D', $version->storage_key);
        $this->assertStringNotContainsString('name', $version->storage_key);
        $this->assertSame(hash('sha256', 'PRIVATE ORIGINAL BYTES'), $version->sha256);
        $this->assertSame(strlen('PRIVATE ORIGINAL BYTES'), $version->byte_size);
        $default = config('filesystems.disks.local');
        $this->assertSame('local', $default['driver']);
        $this->assertSame(storage_path('app/private'), $default['root']);
        $this->assertNotSame(config('filesystems.disks.public.root'), $default['root']);
        $response = $this->get('/storage/'.$version->storage_key);
        $this->assertContains($response->status(), [403, 404]);
        $response->assertDontSee('PRIVATE ORIGINAL BYTES');
        $this->get('/admin/documents/'.$version->public_id)->assertNotFound();
        $this->assertFalse(file_exists(public_path('storage/'.$version->storage_key)));
    }

    public static function storageFaults(): array
    {
        return [['put-false'], ['corrupt-readback'], ['read-false']];
    }

    #[DataProvider('storageFaults')]
    public function test_storage_failure_never_publishes_rows_or_leaves_its_attempt(string $fault): void
    {
        $member = $this->member();
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk)->makePartial();
        if ($fault === 'put-false') {
            $proxy->shouldReceive('put')->andReturn(false);
        } elseif ($fault === 'corrupt-readback') {
            $proxy->shouldReceive('put')->andReturnUsing(fn ($key) => $disk->put($key, 'CORRUPTED'));
        } else {
            $proxy->shouldReceive('readStream')->andReturn(false);
        }
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $this->createDocument($member, 'original');
            $this->fail('Storage failure must not be reported as a valid version.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_versions', 0);
            $this->assertSame([], $disk->allFiles());
        }
    }

    public static function insertFaults(): array
    {
        return [['creating', false], ['created', false], ['creating', true], ['created', true]];
    }

    #[DataProvider('insertFaults')]
    public function test_insert_failure_compensates_only_this_blob_and_retry_appends_safely(string $phase, bool $append): void
    {
        $member = $this->member();
        $first = $append ? $this->createDocument($member, 'historical v1') : null;
        $original = $first ? DocumentVersion::sole()->fresh()->getAttributes() : null;
        $disk = Storage::disk('local');
        $filesBefore = $disk->allFiles();
        $armed = true;
        Event::listen('eloquent.'.$phase.': '.DocumentVersion::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic database insert failure.');
            }
        });
        try {
            $append ? $this->append($member, $first['document_public_id']) : $this->createDocument($member);
            $this->fail('The operation must fail and compensate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic database insert failure.', $error->getMessage());
            $this->assertSame($filesBefore, $disk->allFiles());
            $this->assertDatabaseCount('document_versions', $append ? 1 : 0);
            $this->assertDatabaseCount('documents', $append ? 1 : 0);
            if ($append) {
                $this->assertSame($original, DocumentVersion::sole()->getAttributes());
            }
        } finally {
            $armed = false;
        }
        $retry = $append ? $this->append($member, $first['document_public_id']) : $this->createDocument($member);
        $this->assertSame($append ? 2 : 1, $retry['version_number']);
        $this->assertCount($append ? 2 : 1, $disk->allFiles());
    }

    public function test_outer_transaction_is_rejected_before_bytes_and_production_write_is_disabled(): void
    {
        $member = $this->member();
        DB::beginTransaction();
        try {
            $this->createDocument($member);
            $this->fail('An outer rollback cannot own a filesystem transaction.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('standalone', $error->getMessage());
            $this->assertSame([], Storage::disk('local')->allFiles());
        } finally {
            DB::rollBack();
        }
        $this->app->instance('env', 'production');
        try {
            $this->createDocument($member);
            $this->fail('No operational production intake is allowed.');
        } catch (LogicException) {
            $this->assertDatabaseCount('documents', 0);
        } finally {
            $this->app->instance('env', 'testing');
        }
    }

    public function test_missing_and_corrupt_blobs_fail_integrity_without_replacement(): void
    {
        $member = $this->member();
        $doc = $this->createDocument($member);
        $version = DocumentVersion::sole();
        $disk = Storage::disk('local');
        foreach (['corrupt', null] as $replacement) {
            $replacement === null ? $disk->delete($version->storage_key) : $disk->put($version->storage_key, $replacement);
            try {
                $this->trusted($member, fn () => app(ReadDocumentHistory::class)->verify($member->user, $doc['document_public_id'], $doc['version_public_id']));
                $this->fail('A missing or corrupt file must not produce valid integrity.');
            } catch (RuntimeException) {
                $this->assertDatabaseCount('document_versions', 1);
                $this->assertSame(hash('sha256', 'v1'), $version->fresh()->sha256);
            }
        }
    }

    public function test_revocation_during_unlocked_byte_io_rejects_final_write_and_cleans_attempt(): void
    {
        $member = $this->member();
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('put')->andReturnUsing(function ($key, $stream, $options) use ($disk, $member): bool {
            $member->delete();

            return $disk->put($key, $stream, $options);
        });
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        try {
            $this->createDocument($member);
            $this->fail('Final locked revalidation must reject revoked membership.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('documents', 0);
            $this->assertDatabaseCount('document_versions', 0);
            $this->assertSame([], $disk->allFiles());
        }
    }

    public function test_post_commit_exception_keeps_bytes_of_the_committed_version_and_propagates_error(): void
    {
        $member = $this->member();
        $armed = true;
        Event::listen(TransactionCommitted::class, function () use (&$armed): void {
            if ($armed && DB::table('document_versions')->exists()) {
                $armed = false;
                throw new RuntimeException('Synthetic error after durable COMMIT.');
            }
        });
        try {
            $this->createDocument($member);
            $this->fail('Do not convert an ambiguous outcome into false success.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic error after durable COMMIT.', $error->getMessage());
            $this->assertDatabaseCount('document_versions', 1);
            $version = DocumentVersion::sole();
            $this->assertSame('v1', Storage::disk('local')->get($version->storage_key));
        } finally {
            $armed = false;
        }
    }

    public function test_cleanup_failure_preserves_original_error_and_is_not_silently_successful(): void
    {
        $member = $this->member();
        $disk = Storage::disk('local');
        $proxy = Mockery::mock($disk)->makePartial();
        $proxy->shouldReceive('delete')->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($proxy);
        Event::listen('eloquent.creating: '.DocumentVersion::class, fn () => throw new RuntimeException('Original synthetic SQL fault.'));
        try {
            $this->createDocument($member);
            $this->fail('Cleanup failure must not replace the original error.');
        } catch (RuntimeException $error) {
            $this->assertSame('Original synthetic SQL fault.', $error->getMessage());
            $this->assertDatabaseCount('documents', 0);
            $this->assertCount(1, $disk->allFiles());
        } finally {
            foreach ($disk->allFiles() as $ownedAttempt) {
                $disk->delete($ownedAttempt); // This empty-schema test owns exactly this failed attempt.
            }
        }
    }

    public function test_public_disk_root_is_rejected_before_any_write(): void
    {
        $member = $this->member();
        $publicDisk = Storage::build(['driver' => 'local', 'root' => public_path()]);
        Storage::set('local', $publicDisk);
        $this->expectException(LogicException::class);
        $this->createDocument($member);
    }
}
