<?php

use App\Authorization\ApproachAssignmentAccess;
use Illuminate\Http\Request;
use Tests\Browser\ApproachOperations\Environment;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/Environment.php';
try {
    $c = Environment::configuration();
    if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '127.0.0.4'], true)
        || ($_SERVER['HTTP_HOST'] ?? '') !== $c['http_host'].':'.$c['http_port']) {
        http_response_code(403);
        exit('Owned loopback fixture only.');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath(Environment::root().'/public/'.ltrim(rawurldecode($uri), '/'));
    if (preg_match('~^/(build/|fonts/filament/|js/filament/|css/filament/|favicon\.ico$)~', $uri)
        && preg_match('~\.(css|js|woff2?|ttf|svg|png|ico|webp)$~i', $uri) && $file && is_file($file)
        && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', Environment::root()).'/public/')) {
        return false;
    }
    $f = Environment::fixture($c);
    $livewire = $uri === $f['livewire_script'] && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
        || $uri === $f['livewire_update'] && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
    if (! str_starts_with($uri, '/institution/') && ! $livewire) {
        http_response_code(404);
        exit('Outside the bounded fixture.');
    }
    [$app, $kernel] = Environment::boot(http: true);
    $request = Request::capture();
    ApproachAssignmentAccess::runFixture($request, $f['proof'], function () use ($request, $kernel): void {
        $response = $kernel->handle($request);
        $response->send();
        $kernel->terminate($request, $response);
    });
} catch (Throwable $error) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'Owned Approach fixture could not be verified.';
}
