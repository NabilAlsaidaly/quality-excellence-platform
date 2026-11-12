<?php

namespace App\Services\Documents;

use App\Domain\Documents\Intake\DocumentInspectionLimits;
use App\Domain\Documents\Intake\QuarantinedDocument;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use RuntimeException;
use Throwable;

/** Unbound IO primitive; callers need approved Policy before intake. No release/download operation. */
final class QuarantinedDocumentStore
{
    public function __construct(private FilesystemAdapter $disk, private InspectDocumentFile $inspector) {}

    public function store(string $organizationPublicId, UploadedFile $file, DocumentInspectionLimits $limits): QuarantinedDocument
    {
        $this->root();
        if (! Str::isUlid($organizationPublicId)) {
            throw new LogicException('Trusted organization identity required.');
        }
        $inspection = $this->inspector->execute($file, $limits);
        $id = strtolower((string) Str::ulid());
        $organization = strtolower($organizationPublicId);
        $key = 'quarantine/'.$organization.'/'.$id.'/'.bin2hex(random_bytes(32)).'.blob';
        if (! $this->disk->makeDirectory(dirname($key)) || $this->canonical($this->disk->path(dirname($key))) !== $this->normalized($this->disk->path(dirname($key)))) {
            throw new RuntimeException('تعذر تجهيز الحجر الخاص.');
        }
        $owned = [];
        try {
            $input = fopen($file->getPathname(), 'rb');
            if ($input === false) {
                throw new RuntimeException('تعذرت قراءة المستند.');
            }
            $output = @fopen($this->disk->path($key), 'x+b');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('تعذر حجز محاولة حجر جديدة.');
            }
            $owned[] = $key;
            try {
                $size = stream_copy_to_stream($input, $output, $limits->maximumBytes + 1);
                if ($size !== $inspection->byteSize || ! fflush($output) || ! fsync($output)) {
                    throw new RuntimeException('لم تحفظ بايتات المستند كاملة.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $this->disk->setVisibility($key, 'private');
            $receipt = new QuarantinedDocument($organization, $id, $key, $inspection);
            fclose($this->openForScan($receipt));
            $metadata = @fopen($this->disk->path($key.'.json'), 'x+b');
            if ($metadata === false) {
                throw new RuntimeException('تعذر حجز إيصال محاولة الحجر.');
            }
            $owned[] = $key.'.json';
            try {
                $json = json_encode(['organization_public_id' => $organization, 'intake_public_id' => $id,
                    'inspection' => $inspection, 'released' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (fwrite($metadata, $json) !== strlen($json) || ! fflush($metadata) || ! fsync($metadata)) {
                    throw new RuntimeException('تعذر تثبيت إيصال الحجر.');
                }
            } finally {
                fclose($metadata);
            }
            $this->disk->setVisibility($key.'.json', 'private');

            return $receipt;
        } catch (Throwable $error) {
            foreach (array_reverse($owned) as $attempt) {
                if (! $this->disk->delete($attempt)) {
                    report(new RuntimeException('Owned quarantine attempt requires private recovery inspection.'));
                }
            }
            throw $error;
        }
    }

    /** Internal scanner stream only. An approved download Action must never skip its own Policy. */
    public function openForScan(QuarantinedDocument $receipt): mixed
    {
        $this->root();
        $prefix = 'quarantine/'.$receipt->organizationPublicId.'/'.$receipt->intakePublicId.'/';
        if (! Str::isUlid($receipt->organizationPublicId) || ! Str::isUlid($receipt->intakePublicId)
            || ! str_starts_with($receipt->storageKey, $prefix)
            || ! preg_match('~\Aquarantine/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[a-f0-9]{64}\.blob\z~', $receipt->storageKey)
            || $this->canonical($this->disk->path($receipt->storageKey)) !== $this->normalized($this->disk->path($receipt->storageKey))) {
            throw new RuntimeException('مرجع الحجر الخاص غير صالح.');
        }
        $stream = @fopen($this->disk->path($receipt->storageKey), 'rb');
        if ($stream === false) {
            throw new RuntimeException('بيانات الحجر غير متاحة.');
        }
        $hash = hash_init('sha256');
        $size = hash_update_stream($hash, $stream);
        if ($size !== $receipt->inspection->byteSize || ! hash_equals($receipt->inspection->sha256, hash_final($hash)) || ! rewind($stream)) {
            fclose($stream);
            throw new RuntimeException('فشل التحقق من سلامة المستند المحجور.');
        }

        return $stream;
    }

    private function root(): void
    {
        $path = $this->disk->path('');
        if (! $this->disk->getAdapter() instanceof LocalFilesystemAdapter || $this->canonical($path) !== $this->normalized($path)) {
            throw new LogicException('An explicit canonical private local disk is required.');
        }
        foreach ([public_path(), storage_path('app/public')] as $forbidden) {
            if (str_starts_with($this->canonical($path).'/', $this->normalized($forbidden).'/')) {
                throw new LogicException('Refuse document quarantine inside public storage.');
            }
        }
        QuarantineDirectoryPrivacy::assertPrivate($path);
    }

    private function canonical(string $path): string
    {
        $actual = realpath($path);

        return $actual === false ? '' : $this->normalized($actual);
    }

    private function normalized(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
