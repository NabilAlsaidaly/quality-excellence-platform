<?php

namespace App\Actions\Documents;

use App\Domain\Documents\Intake\IntakeRules;
use App\Domain\Documents\Intake\ScanResult;
use App\Models\Organization;
use App\Models\User;
use Closure;
use finfo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use League\Flysystem\Local\LocalFilesystemAdapter;
use LogicException;
use RuntimeException;
use Throwable;

/** Internal test-only intake experiment. No HTTP route, download or DocumentVersion promotion. */
final class QuarantineTestFile
{
    public function __construct(private CreateDocumentWithVersion $boundary) {}

    /** @param Closure(resource): ScanResult $scanner Explicit test scanner; no operational AV is selected. */
    public function execute(?User $actor, UploadedFile $file, IntakeRules $rules, FilesystemAdapter $disk, Closure $scanner): array
    {
        if (! app()->environment('testing')) {
            throw new LogicException('Quarantine fixture intake requires testing.');
        }
        $db = DB::connection();
        if ($db->getDriverName() === 'sqlite' && $db->getDatabaseName() === ':memory:') {
            if (collect($db->select('PRAGMA database_list'))->firstWhere('name', 'main')?->file !== '') {
                throw new LogicException('An actual ephemeral database is required.');
            }
        } elseif ($db->getDriverName() === 'mysql' && $db->getDatabaseName() === 'quality_platform_ci'
            && ! config('database.connections.mysql.url') && is_string(getenv('MVP_FIXTURE_MYSQL_UUID'))) {
            $actual = $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid, VERSION() AS version');
            if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
                || ! hash_equals(getenv('MVP_FIXTURE_MYSQL_UUID'), $actual->uuid)) {
                throw new LogicException('Verified owned fixture database required.');
            }
        } else {
            throw new LogicException('Fixture intake refuses normal databases.');
        }
        $root = realpath($disk->path(''));
        $testing = realpath(storage_path('framework/testing/disks'));
        if (! $disk->getAdapter() instanceof LocalFilesystemAdapter || $root === false || $testing === false
            || $this->path($root) !== $this->path($disk->path(''))
            || $this->path($testing) !== $this->path(storage_path('framework/testing/disks'))
            || ! str_starts_with($this->path($root), $this->path($testing).'/')) {
            throw new LogicException('An explicitly isolated private testing disk is required.');
        }
        $organization = $this->boundary->withinContext($actor, fn (Organization $organization) => $organization->public_id);
        if (! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'تعذرت قراءة ملف الاختبار.']);
        }
        $filename = $file->getClientOriginalName();
        $this->boundary->validateMetadata(['original_filename' => $filename], false);
        $rules->validate($filename, $file->getMimeType() ?? '', $file->getSize());
        $id = strtolower((string) Str::ulid());
        $key = 'quarantine/'.strtolower($organization).'/'.$id.'/'.bin2hex(random_bytes(24)).'.blob';
        $directory = $disk->path(dirname($key));
        if (! $disk->makeDirectory(dirname($key)) || realpath($directory) === false
            || $this->path(realpath($directory)) !== $this->path($directory)) {
            throw new RuntimeException('تعذر تجهيز الحجر الخاص بالاختبار.');
        }
        $owned = [];
        try {
            $input = fopen($file->getPathname(), 'rb');
            if ($input === false) {
                throw new RuntimeException('تعذرت قراءة الملف.');
            }
            $output = @fopen($disk->path($key), 'x+b');
            if ($output === false) {
                fclose($input);
                throw new RuntimeException('تعذر حجز ملف حجر جديد.');
            }
            $owned[] = $key;
            try {
                $copied = stream_copy_to_stream($input, $output, $rules->maximumBytes + 1);
                if ($copied === false || ! fflush($output)) {
                    throw new RuntimeException('تعذر حفظ بيانات الحجر.');
                }
            } finally {
                fclose($input);
                fclose($output);
            }
            $disk->setVisibility($key, 'private');
            clearstatcache(true, $disk->path($key));
            $size = filesize($disk->path($key));
            $type = (new finfo(FILEINFO_MIME_TYPE))->file($disk->path($key));
            $rules->validate($filename, $type ?: '', $size === false ? 0 : $size);
            $digest = hash_file('sha256', $disk->path($key));
            $read = fopen($disk->path($key), 'rb');
            if ($read === false || $digest === false) {
                throw new RuntimeException('تعذر التحقق من بيانات الحجر.');
            }
            try {
                try {
                    $scan = $scanner($read);
                    if (! $scan instanceof ScanResult) {
                        $scan = ScanResult::Unavailable;
                    }
                } catch (Throwable) {
                    $scan = ScanResult::Unavailable;
                }
            } finally {
                fclose($read);
            }
            clearstatcache(true, $disk->path($key));
            if (! hash_equals($digest, hash_file('sha256', $disk->path($key)) ?: '') || filesize($disk->path($key)) !== $size) {
                throw new RuntimeException('تغيرت بيانات الحجر أثناء الفحص.');
            }
            $this->boundary->withinContext($actor, function (Organization $current) use ($organization): void {
                if ($current->public_id !== $organization) {
                    throw new AuthorizationException;
                }
            });
            $receipt = ['quarantine_public_id' => $id, 'organization_public_id' => $organization,
                'original_filename' => $filename, 'detected_media_type' => $type, 'byte_size' => $size,
                'sha256' => $digest, 'scan_result' => $scan->value, 'servable' => false];
            $metadataKey = $key.'.json';
            $metadata = @fopen($disk->path($metadataKey), 'x+b');
            if ($metadata === false) {
                throw new RuntimeException('تعذر حجز سجل الحجر.');
            }
            $owned[] = $metadataKey;
            try {
                $json = json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (fwrite($metadata, $json) !== strlen($json) || ! fflush($metadata)) {
                    throw new RuntimeException('تعذر حفظ سجل الحجر.');
                }
            } finally {
                fclose($metadata);
            }
            $disk->setVisibility($metadataKey, 'private');

            return $receipt;
        } catch (Throwable $error) {
            foreach (array_reverse($owned) as $attempt) {
                if (! $disk->delete($attempt)) {
                    report(new RuntimeException('Owned quarantine fixture compensation requires inspection.'));
                }
            }
            throw $error;
        }
    }

    private function path(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return DIRECTORY_SEPARATOR === '\\' ? strtolower($path) : $path;
    }
}
