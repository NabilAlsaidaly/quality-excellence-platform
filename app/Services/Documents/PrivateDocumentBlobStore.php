<?php

namespace App\Services\Documents;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal synthetic-byte facility. Never a user intake/download service. */
final class PrivateDocumentBlobStore
{
    private array $owned = [];

    public function write(string $organization, string $document, string $version, mixed $stream): array
    {
        $disk = $this->disk();
        foreach ([$organization, $document, $version] as $id) {
            if (! Str::isUlid($id)) {
                throw new LogicException('Blob scope requires trusted public identifiers.');
            }
        }
        if (! is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw ValidationException::withMessages(['file' => 'تقبل هذه النواة تدفق بيانات اختبار داخليًا فقط.']);
        }
        $meta = stream_get_meta_data($stream);
        if (! ($meta['seekable'] ?? false) || preg_match('#^php://(?:memory|temp(?:/maxmemory:\d+)?)$#D', $meta['uri'] ?? '') !== 1
            || ftell($stream) !== 0) {
            throw ValidationException::withMessages(['file' => 'تدفق الاختبار غير صالح أو ليس في بدايته.']);
        }
        $hash = hash_init('sha256');
        $size = hash_update_stream($hash, $stream);
        $digest = hash_final($hash);
        rewind($stream);
        if ($size === 0) {
            throw ValidationException::withMessages(['file' => 'لا يمكن حفظ تدفق فارغ.']);
        }
        $key = 'documents/'.strtolower($organization).'/'.strtolower($document).'/'.strtolower($version).'/'.bin2hex(random_bytes(32)).'.blob';
        $receipt = ['storage_disk' => 'local', 'storage_key' => $key, 'sha256' => $digest, 'byte_size' => $size,
            'organization_public_id' => $organization, 'document_public_id' => $document, 'version_public_id' => $version];
        if (! $disk->makeDirectory(dirname($key))) {
            throw new RuntimeException('تعذّر تجهيز التخزين الخاص.');
        }
        // Reserve exclusively. Never overwrite/adopt another attempt's file.
        $reservation = @fopen($disk->path($key), 'x+b');
        if ($reservation === false) {
            throw new RuntimeException('تعذّر حجز ملف اختبار جديد.');
        }
        fclose($reservation);
        $this->owned[$key] = true;
        try {
            if (! $disk->put($key, $stream, ['visibility' => 'private'])) {
                throw new RuntimeException('تعذّرت كتابة بيانات الاختبار الخاصة.');
            }
            $this->verify($receipt);

            return $receipt;
        } catch (Throwable $error) {
            $this->compensate($receipt, $error);
        } finally {
            if (is_resource($stream)) {
                rewind($stream);
            }
        }
    }

    /** Trusted persisted receipt only; no HTTP caller accepts a key or disk. Returns no bytes. */
    public function verify(array $receipt): array
    {
        $disk = $this->disk();
        $key = $receipt['storage_key'] ?? '';
        if (($receipt['storage_disk'] ?? null) !== 'local'
            || preg_match('#^documents/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[0-9a-hjkmnp-tv-z]{26}/[0-9a-f]{64}\.blob$#D', $key) !== 1) {
            throw new RuntimeException('مرجع التخزين الداخلي غير صالح.');
        }
        $stream = $disk->readStream($key);
        if (! is_resource($stream)) {
            throw new RuntimeException('تعذّر التحقق من سلامة الملف الخاص.');
        }
        try {
            $hash = hash_init('sha256');
            $size = hash_update_stream($hash, $stream);
            $digest = hash_final($hash);
        } finally {
            fclose($stream);
        }
        if ($size !== ($receipt['byte_size'] ?? null) || ! hash_equals($receipt['sha256'] ?? '', $digest)) {
            throw new RuntimeException('فشل التحقق من سلامة الملف الخاص.');
        }

        return ['sha256' => $digest, 'byte_size' => $size];
    }

    public function retain(array $receipt): void
    {
        unset($this->owned[$receipt['storage_key']]);
    }

    /** Remove only this instance's uncommitted attempt, never a historical version. */
    public function compensate(array $receipt, Throwable $original): never
    {
        try {
            $key = $receipt['storage_key'];
            if (! isset($this->owned[$key])) {
                throw new LogicException('Refuse cleanup of an unowned or retained blob.');
            }
            if (! $this->disk()->delete($key)) {
                throw new RuntimeException('Attempt cleanup failed; private recovery inspection required.');
            }
            unset($this->owned[$key]);
        } catch (Throwable $cleanup) {
            // Preserve the original error. No bytes/path are included in the message.
            report(new RuntimeException('Synthetic document compensation requires operator inspection.', previous: $cleanup));
        }
        throw $original;
    }

    private function disk(): FilesystemAdapter
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Synthetic document storage is disabled outside local/testing.');
        }
        $disk = Storage::disk('local');
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter) {
            throw new LogicException('Synthetic documents require the private local adapter.');
        }
        $root = realpath($disk->path(''));
        $public = realpath(public_path());
        $publicDisk = realpath(storage_path('app/public'));
        if ($root === false || is_link(rtrim($disk->path(''), '/\\'))) {
            throw new LogicException('A real private storage root is required.');
        }
        $normalized = strtolower(str_replace('\\', '/', $root)).'/';
        foreach (array_filter([$public, $publicDisk]) as $forbidden) {
            if (str_starts_with($normalized, strtolower(str_replace('\\', '/', $forbidden)).'/')) {
                throw new LogicException('Refuse public document storage.');
            }
        }

        return $disk;
    }
}
