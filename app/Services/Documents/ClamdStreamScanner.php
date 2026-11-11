<?php

namespace App\Services\Documents;

use App\Domain\Documents\Intake\DocumentMalwareScanner;
use App\Domain\Documents\Intake\ScanResult;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Real clamd INSTREAM adapter, deliberately unregistered until the scanner environment is approved. */
final readonly class ClamdStreamScanner implements DocumentMalwareScanner
{
    public function __construct(private string $endpoint, private float $timeoutSeconds)
    {
        $tcp = preg_match('~\Atcp://127\.0\.0\.1:([0-9]{1,5})\z~', $endpoint, $match) === 1
            && (int) $match[1] >= 1 && (int) $match[1] <= 65535;
        $unix = preg_match('~\Aunix:///[a-zA-Z0-9_./-]+\z~', $endpoint) === 1 && ! str_contains($endpoint, '/..');
        if ((! $tcp && ! $unix) || ! is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new InvalidArgumentException('حدد مأخذ فحص محليًا موثوقًا ومهلة محدودة.');
        }
    }

    public function scan(mixed $stream, int $maximumBytes): ScanResult
    {
        if ($maximumBytes < 1 || $maximumBytes >= PHP_INT_MAX || ! is_resource($stream)
            || get_resource_type($stream) !== 'stream' || ! (stream_get_meta_data($stream)['seekable'] ?? false) || ftell($stream) !== 0) {
            return ScanResult::Unavailable;
        }
        $deadline = hrtime(true) / 1e9 + $this->timeoutSeconds;
        $socket = null;
        try {
            $socket = @stream_socket_client($this->endpoint, $errno, $error, $this->timeoutSeconds, STREAM_CLIENT_CONNECT);
            if ($socket === false) {
                return ScanResult::Unavailable;
            }
            $this->write($socket, "zINSTREAM\0", $deadline);
            $size = 0;
            while (! feof($stream)) {
                $this->remaining($deadline);
                $chunk = fread($stream, 8192);
                if ($chunk === false || ($chunk === '' && ! feof($stream))) {
                    throw new RuntimeException('تعذرت قراءة المستند للفحص.');
                }
                if ($chunk === '') {
                    break;
                }
                $size += strlen($chunk);
                if ($size > $maximumBytes) {
                    return ScanResult::Unavailable;
                }
                $this->write($socket, pack('N', strlen($chunk)).$chunk, $deadline);
            }
            if ($size === 0) {
                return ScanResult::Unavailable;
            }
            $this->write($socket, pack('N', 0), $deadline);
            $reply = '';
            // One non-session command. Read through EOF, not merely the first promising record.
            while (! feof($socket)) {
                $this->limit($socket, $deadline);
                $part = @fread($socket, 1025 - strlen($reply));
                if ($part === false || ($part === '' && ! feof($socket))) {
                    throw new RuntimeException('تعذر إتمام رد الفحص.');
                }
                $reply .= $part;
                if (strlen($reply) > 1024) {
                    return ScanResult::Unavailable;
                }
            }

            return match (true) {
                $reply === "stream: OK\0" => ScanResult::Clean,
                preg_match('/\Astream: [^\x00\r\n]{1,512} FOUND\x00\z/', $reply) === 1 => ScanResult::Suspect,
                default => ScanResult::Unavailable,
            };
        } catch (Throwable) {
            return ScanResult::Unavailable;
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            rewind($stream);
        }
    }

    private function write(mixed $socket, string $bytes, float $deadline): void
    {
        while ($bytes !== '') {
            $this->limit($socket, $deadline);
            $written = @fwrite($socket, $bytes);
            if ($written === false || $written === 0) {
                throw new RuntimeException('تعذر إرسال المستند للفحص.');
            }
            $bytes = substr($bytes, $written);
        }
    }

    private function remaining(float $deadline): float
    {
        $remaining = $deadline - hrtime(true) / 1e9;
        if ($remaining <= 0) {
            throw new RuntimeException('انتهت مهلة الفحص.');
        }

        return $remaining;
    }

    private function limit(mixed $socket, float $deadline): void
    {
        $remaining = $this->remaining($deadline);
        stream_set_timeout($socket, (int) $remaining, max(1, (int) (($remaining - (int) $remaining) * 1000000)));
    }
}
