<?php

// Wire-protocol fixture, NOT antivirus. Harmless bytes only; no operational scanner-clean evidence.
if (PHP_SAPI !== 'cli' || count($argv) !== 3) {
    exit(2);
}
$directory = realpath($argv[1]);
$base = realpath(dirname(__DIR__, 2).'/storage/framework/testing');
if ($directory === false || $base === false || ! str_starts_with(str_replace('\\', '/', $directory).'/', str_replace('\\', '/', $base).'/')) {
    exit(3);
}
$server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($server === false) {
    exit(4);
}
file_put_contents($directory.'/ready.json', json_encode(['address' => stream_socket_get_name($server, false)], JSON_THROW_ON_ERROR));
$socket = stream_socket_accept($server, 10);
if ($socket === false) {
    exit(5);
}
stream_set_timeout($socket, 5);
$read = static function (int $count) use ($socket): string {
    $buffer = '';
    while (strlen($buffer) < $count) {
        $next = fread($socket, $count - strlen($buffer));
        if ($next === false || $next === '') {
            exit(6);
        }
        $buffer .= $next;
    }

    return $buffer;
};
if ($read(10) !== "zINSTREAM\0") {
    exit(7);
}
$hash = hash_init('sha256');
$size = 0;
while (($length = unpack('Nlength', $read(4))['length']) !== 0) {
    if ($length > 8192 || $size + $length > 2000000) {
        exit(8);
    }
    $chunk = $read($length);
    $size += strlen($chunk);
    hash_update($hash, $chunk);
}
file_put_contents($directory.'/received.json', json_encode(['bytes' => $size, 'sha256' => hash_final($hash)], JSON_THROW_ON_ERROR));
$reply = match ($argv[2]) {
    'clean' => "stream: OK\0", 'suspect' => "stream: Harmless.Protocol.Test FOUND\0",
    'error' => "stream: INSTREAM size limit exceeded ERROR\0", 'partial' => 'stream: OK',
    'spoof' => "other: OK\0", 'extra' => "stream: OK\0unexpected\0", 'closed', 'timeout' => '',
    default => "ERROR\0",
};
if ($argv[2] === 'timeout') {
    usleep(500000);
}
if ($reply !== '') {
    fwrite($socket, $reply);
}
fclose($socket);
fclose($server);
