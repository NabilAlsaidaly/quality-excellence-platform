<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Domain\Documents\Intake\QuarantinedDocument;
use App\Services\Documents\InspectDocumentFile;
use App\Services\Documents\QuarantinedDocumentStore;
use App\Services\Documents\QuarantineDirectoryPrivacy;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\Support\HarmlessDocumentSamples;
use Tests\Support\PrivateIntakeTestDisk;
use Tests\TestCase;

class QuarantinedDocumentStoreTest extends TestCase
{
    use PrivateIntakeTestDisk;

    public function test_real_bytes_are_private_immutable_and_never_released_by_storage(): void
    {
        $disk = $this->privateDisk();
        $store = new QuarantinedDocumentStore($disk, new InspectDocumentFile);
        $bytes = HarmlessDocumentSamples::pdf();
        $org = (string) Str::ulid();
        $limits = new DocumentInspectionLimits(100000, 200000, 40);
        $a = $store->store($org, UploadedFile::fake()->createWithContent('one.pdf', $bytes), $limits);
        $b = $store->store($org, UploadedFile::fake()->createWithContent('two.pdf', $bytes), $limits);
        $this->assertNotSame($a->storageKey, $b->storageKey);
        $this->assertStringNotContainsString('one.pdf', $a->storageKey);
        $this->assertSame(strtolower($org), $a->organizationPublicId);
        $this->assertSame(hash('sha256', $bytes), $a->inspection->sha256);
        $this->assertFalse($a->isReleased());
        $stream = $store->openForScan($a);
        try {
            $this->assertSame($bytes, stream_get_contents($stream));
        } finally {
            fclose($stream);
        }
        // Windows chmod/visibility bits do not describe NTFS access; inspect actual ACLs.
        if (PHP_OS_FAMILY === 'Windows') {
            QuarantineDirectoryPrivacy::assertPrivate($disk->path($a->storageKey));
            $this->addToAssertionCount(1);
        } else {
            $this->assertSame('private', $disk->getVisibility($a->storageKey));
        }
        $this->assertFileDoesNotExist(public_path($a->storageKey));
        $this->assertCount(4, $disk->allFiles());
    }

    public function test_tampering_with_quarantined_bytes_fails_closed(): void
    {
        $disk = $this->privateDisk();
        $store = new QuarantinedDocumentStore($disk, new InspectDocumentFile);
        $a = $store->store((string) Str::ulid(), UploadedFile::fake()->createWithContent('one.pdf', HarmlessDocumentSamples::pdf()),
            new DocumentInspectionLimits(100000, 200000, 40));
        $disk->put($a->storageKey, 'corrupted');
        $this->expectException(RuntimeException::class);
        $store->openForScan($a);
    }

    public function test_a_receipt_cannot_be_relabelled_for_another_tenant(): void
    {
        $disk = $this->privateDisk();
        $store = new QuarantinedDocumentStore($disk, new InspectDocumentFile);
        $a = $store->store((string) Str::ulid(), UploadedFile::fake()->createWithContent('one.pdf', HarmlessDocumentSamples::pdf()),
            new DocumentInspectionLimits(100000, 200000, 40));
        $forged = new QuarantinedDocument(strtolower((string) Str::ulid()), $a->intakePublicId, $a->storageKey, $a->inspection);
        $this->expectException(RuntimeException::class);
        $store->openForScan($forged);
    }

    public function test_an_unprovisioned_disk_is_refused_before_any_bytes_are_accepted(): void
    {
        $disk = Storage::fake('issue68-unprovisioned-'.bin2hex(random_bytes(8)));
        if (PHP_OS_FAMILY !== 'Windows') {
            chmod($disk->path(''), 0755);
        }
        $this->expectException(LogicException::class);
        try {
            (new QuarantinedDocumentStore($disk, new InspectDocumentFile))->store((string) Str::ulid(),
                UploadedFile::fake()->createWithContent('one.pdf', HarmlessDocumentSamples::pdf()), new DocumentInspectionLimits(100000, 200000, 40));
        } finally {
            $this->assertSame([], $disk->allFiles());
        }
    }
}
