<?php

namespace Tests\Feature\Documents;

use App\Domain\Documents\Intake\ScanResult;
use App\Services\Documents\ClamdStreamScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\Support\HarmlessDocumentSamples;
use Tests\TestCase;

/** Actual loopback protocol peer, explicitly not a ClamAV engine or real scan acceptance. */
class ClamdStreamScannerTest extends TestCase
{
    public static function replies(): array
    {
        return [['clean', 'clean'], ['suspect', 'suspect'], ['error', 'unavailable'], ['partial', 'unavailable'],
            ['spoof', 'unavailable'], ['extra', 'unavailable'], ['closed', 'unavailable'], ['timeout', 'unavailable']];
    }

    #[DataProvider('replies')]
    public function test_exact_framing_stream_identity_and_fail_closed_results(string $mode, string $expected): void
    {
        $directory = storage_path('framework/testing/clamd-peer-'.bin2hex(random_bytes(8)));
        mkdir($directory, 0700, true);
        $peer = new Process([PHP_BINARY, base_path('tests/Support/ClamdProtocolPeer.php'), $directory, $mode]);
        $peer->setTimeout(12);
        $peer->start();
        $stream = fopen('php://temp', 'w+b');
        $bytes = HarmlessDocumentSamples::pdf().str_repeat('harmless', 3000);
        fwrite($stream, $bytes);
        rewind($stream);
        try {
            $ready = $directory.'/ready.json';
            $deadline = microtime(true) + 5;
            while (! is_file($ready) && $peer->isRunning() && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertFileExists($ready);
            $address = json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR)['address'];
            $scanner = new ClamdStreamScanner('tcp://'.$address, $mode === 'timeout' ? 0.15 : 3.0);
            $this->assertSame(ScanResult::from($expected), $scanner->scan($stream, 100000));
            $this->assertSame(0, ftell($stream));
            $peer->wait();
            $this->assertSame(0, $peer->getExitCode(), $peer->getErrorOutput());
            $received = json_decode(file_get_contents($directory.'/received.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(strlen($bytes), $received['bytes']);
            $this->assertSame(hash('sha256', $bytes), $received['sha256']);
        } finally {
            if ($peer->isRunning()) {
                $peer->stop();
            }
            fclose($stream);
            foreach (['ready.json', 'received.json'] as $name) {
                if (is_file($directory.'/'.$name)) {
                    unlink($directory.'/'.$name);
                }
            }
            rmdir($directory);
        }
    }
}
