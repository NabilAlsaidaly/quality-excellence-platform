<?php

namespace App\Actions\Documents;

use App\Domain\Documents\Intake\ScanResult;
use App\Models\User;
use App\Services\Documents\ClamdStreamScanner;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/** Explicitly constructed only; no worker, binding, endpoint or permission is registered. */
final class ProcessDocumentIntake
{
    private ClamdStreamScanner $scanner;

    public function __construct(private RegisterDocumentIntake $boundary, private string $endpoint,
        private float $timeoutSeconds, private int $leaseSeconds)
    {
        $this->scanner = new ClamdStreamScanner($endpoint, $timeoutSeconds);
        if ($leaseSeconds < 3 * $timeoutSeconds + 5 || $leaseSeconds > 300) {
            throw new InvalidArgumentException('Scanner lease must cover bounded health and stream IO.');
        }
    }

    public function execute(?User $actor, string $intakeId, int $expectedGeneration, string $requestKey): array
    {
        if (DB::transactionLevel() !== 0 || ! Str::isUlid($requestKey)) {
            throw new LogicException('Scanning must not hold an outer SQL transaction.');
        }
        $documentId = $this->boundary->documentForIntake($intakeId);
        $operation = $this->operation($intakeId);
        $lease = bin2hex(random_bytes(32));
        $claimed = $this->boundary->within($actor, $operation, $documentId,
            function (User $current) use ($intakeId, $expectedGeneration, $requestKey, $lease): array {
                $intake = $this->boundary->intake($intakeId);
                if ((int) $intake->actor_id !== (int) $current->id) {
                    throw new AuthorizationException;
                }
                $prior = DB::table('document_intake_events')->where('organization_id', $intake->organization_id)
                    ->where('intake_id', $intake->id)->where('request_key', $requestKey)->lockForUpdate()->first();
                if ($prior !== null) {
                    $details = json_decode($prior->details_json, true, 64, JSON_THROW_ON_ERROR);
                    if ($prior->event !== 'scan_started' || (int) $prior->actor_id !== (int) $current->id
                        || ($details['expected_generation'] ?? null) !== $expectedGeneration) {
                        throw new ConflictHttpException('معرّف الفحص مستخدم لطلب آخر.');
                    }

                    return ['claimed' => false, 'state' => $intake->state, 'generation' => $intake->generation];
                }
                $expired = $intake->state === 'scanning' && $intake->lease_expires_at <= RegisterDocumentIntake::timestamp();
                if ($intake->generation !== $expectedGeneration || $expectedGeneration >= 4294967295
                    || (! in_array($intake->state, ['pending', 'failed'], true) && ! $expired)) {
                    throw new ConflictHttpException('الفحص جارٍ أو تغيرت حالته.');
                }
                $generation = $expectedGeneration + 1;
                if (DB::table('document_intakes')->where('id', $intake->id)->where('generation', $expectedGeneration)
                    ->where('state', $intake->state)->update(['state' => 'scanning', 'scan_result' => null, 'generation' => $generation,
                        'lease_hash' => hash('sha256', $lease), 'lease_expires_at' => now('UTC')->addSeconds($this->leaseSeconds)->format('Y-m-d H:i:s'),
                        'updated_at' => RegisterDocumentIntake::timestamp()]) !== 1) {
                    throw new ConflictHttpException('سبق استلام الفحص من عامل آخر.');
                }
                $intake = DB::table('document_intakes')->where('id', $intake->id)->first();
                $this->boundary->event($intake, $current, 'scan_started', $requestKey, ['expected_generation' => $expectedGeneration]);

                return ['claimed' => true, 'intake' => $intake, 'receipt' => $this->boundary->receipt($intake)];
            });
        if (! $claimed['claimed']) {
            return $claimed;
        }
        $result = ScanResult::Unavailable;
        $health = [];
        try {
            $health = $this->health();
            $stream = $this->boundary->openVerified($claimed['receipt']);
            try {
                $result = $this->scanner->scan($stream, 30000000);
            } finally {
                fclose($stream);
            }
            // A clean reply cannot attest bytes that changed during the scan.
            fclose($this->boundary->openVerified($claimed['receipt']));
        } catch (Throwable) {
            $result = ScanResult::Unavailable;
        }

        return $this->boundary->within($actor, $operation, $documentId,
            function (User $current) use ($intakeId, $claimed, $lease, $result, $health): array {
                $intake = $this->boundary->intake($intakeId);
                if ((int) $intake->actor_id !== (int) $current->id || $intake->state !== 'scanning'
                    || $intake->generation !== $claimed['intake']->generation || ! hash_equals($intake->lease_hash ?? '', hash('sha256', $lease))
                    || $intake->lease_expires_at <= RegisterDocumentIntake::timestamp()) {
                    throw new ConflictHttpException('انتهت ملكية محاولة الفحص؛ لا تعتمد نتيجة قديمة.');
                }
                $state = match ($result) {
                    ScanResult::Clean => 'clean', ScanResult::Suspect => 'suspect', ScanResult::Unavailable => 'failed'
                };
                if (DB::table('document_intakes')->where('id', $intake->id)->where('generation', $intake->generation)
                    ->where('lease_hash', hash('sha256', $lease))->update(['state' => $state, 'scan_result' => $result->value,
                        'lease_hash' => null, 'lease_expires_at' => null, 'updated_at' => RegisterDocumentIntake::timestamp()]) !== 1) {
                    throw new ConflictHttpException('تغيرت ملكية الفحص قبل تثبيت النتيجة.');
                }
                $this->boundary->event($intake, $current, match ($result) {
                    ScanResult::Clean => 'scan_clean', ScanResult::Suspect => 'scan_suspect', ScanResult::Unavailable => 'scan_failed',
                }, (string) Str::ulid(), $health + ['sha256' => $claimed['receipt']->inspection->sha256,
                    'byte_size' => $claimed['receipt']->inspection->byteSize]);

                return ['state' => $state, 'generation' => $intake->generation];
            });
    }

    private function operation(string $intakeId): string
    {
        return DB::transaction(function () use ($intakeId): string {
            $intake = $this->boundary->intake($intakeId);
            $number = DB::table('document_versions')->where('organization_id', $intake->organization_id)
                ->where('document_id', $intake->document_id)->where('id', $intake->version_id)->value('version_number');

            return $number === 1 ? 'upload_real' : 'append_real';
        });
    }

    private function health(): array
    {
        if ($this->command('PING') !== "PONG\0") {
            throw new RuntimeException('Scanner health is unavailable.');
        }
        $version = $this->command('VERSION');
        if (preg_match('/\AClamAV ([0-9][0-9A-Za-z.+_-]*)\/([1-9][0-9]*)\/([^\x00\r\n]{1,100})\x00\z/', $version, $parts) !== 1) {
            throw new RuntimeException('No verified signature database identity.');
        }
        $date = DateTimeImmutable::createFromFormat('D M j H:i:s Y', preg_replace('/\s+/', ' ', trim($parts[3])), new DateTimeZone('UTC'));
        if ($date === false || $date->getTimestamp() > time()) {
            throw new RuntimeException('Invalid signature database timestamp.');
        }

        return ['engine_version' => $parts[1], 'database_version' => (int) $parts[2], 'database_timestamp' => $date->format('Y-m-d\TH:i:s\Z')];
    }

    private function command(string $command): string
    {
        $socket = @stream_socket_client($this->endpoint, $errno, $error, $this->timeoutSeconds, STREAM_CLIENT_CONNECT);
        if (! is_resource($socket)) {
            throw new RuntimeException('Scanner is unavailable.');
        }
        $deadline = microtime(true) + $this->timeoutSeconds;
        try {
            $bytes = 'z'.$command."\0";
            $reply = '';
            while ($bytes !== '' || ! feof($socket)) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    throw new RuntimeException('Scanner health timed out.');
                }
                stream_set_timeout($socket, (int) $remaining, max(1, (int) (($remaining - (int) $remaining) * 1000000)));
                if ($bytes !== '') {
                    $written = @fwrite($socket, $bytes);
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Incomplete scanner request.');
                    }
                    $bytes = substr($bytes, $written);
                } else {
                    $part = @fread($socket, 1025 - strlen($reply));
                    if ($part === false || ($part === '' && ! feof($socket))) {
                        throw new RuntimeException('Incomplete scanner response.');
                    }
                    $reply .= $part;
                    if (strlen($reply) > 1024) {
                        throw new RuntimeException('Invalid scanner response.');
                    }
                }
            }

            return $reply;
        } finally {
            fclose($socket);
        }
    }
}
