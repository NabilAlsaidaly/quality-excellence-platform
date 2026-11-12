<?php

use Filament\Facades\Filament;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Browser\ReviewMaturity\Environment;
use Tests\Support\ReviewMaturityPanelProvider;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/Environment.php';
try {
    $c = Environment::configuration();
    if (PHP_SAPI !== 'cli-server' || ! in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '127.0.0.6'], true)
        || ($_SERVER['HTTP_HOST'] ?? '') !== $c['http_host'].':'.$c['http_port']) {
        http_response_code(403);
        exit('Owned loopback document fixture only.');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath(Environment::root().'/public/'.ltrim(rawurldecode($uri), '/'));
    if (preg_match('~^/(build/|fonts/filament/|js/filament/|css/filament/|favicon\.ico$)~', $uri)
        && preg_match('~\.(css|js|woff2?|ttf|svg|png|ico|webp)$~i', $uri) && $file && is_file($file)
        && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', Environment::root()).'/public/')) {
        return false;
    }
    $f = Environment::fixture($c);
    $livewire = ($uri === $f['livewire_script'] && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET')
        || ($uri === $f['livewire_update'] && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST')
        || (isset($f['livewire_upload']) && $uri === $f['livewire_upload'] && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST');
    if (! str_starts_with($uri, '/institution/') && ! $livewire) {
        http_response_code(404);
        exit('Outside the bounded document fixture.');
    }
    [$app, $kernel] = Environment::boot(http: true);
    $app->make(ExceptionHandler::class)->map(
        HttpExceptionInterface::class,
        function ($error) {
            // Private source locations only: never request data, messages or trace arguments.
            file_put_contents(Environment::privateRoot().'/http-diagnostic.json', json_encode([
                'status' => $error->getStatusCode(),
                'panel' => Filament::getCurrentPanel()?->getId(),
                'trace' => array_map(fn ($frame) => [basename($frame['file'] ?? ''), $frame['line'] ?? 0,
                    $frame['class'] ?? '', $frame['function'] ?? ''], array_slice($error->getTrace(), 0, 12)),
            ], JSON_THROW_ON_ERROR));

            return $error;
        });
    $request = Request::capture();
    ReviewMaturityPanelProvider::withFixture($f['panel_proof'], function () use ($kernel, $request, $c): void {
        $response = $kernel->handle($request);
        if ((ReviewMaturityPanelProvider::proof()['document']['file_operation_scope'] ?? null) === 'CLOSURE_FILES_LOCAL_001' && str_ends_with($request->path(), '/download')) {
            file_put_contents(Environment::privateRoot().'/download-http-diagnostic.json', json_encode([
                'response_class' => get_class($response), 'status' => $response->getStatusCode(),
                'disposition' => $response->headers->get('Content-Disposition'), 'headers_sent' => headers_sent(),
                'php_status' => http_response_code(), 'method' => $request->method(),
                'accept' => $request->header('Accept'), 'fetch_mode' => $request->header('Sec-Fetch-Mode'),
            ], JSON_THROW_ON_ERROR));
        }
        $response->headers->set('X-Qep-Review-Maturity-Fixture', $c['fixture_id']);
        $response->send();
        $kernel->terminate($request, $response);
    });
} catch (Throwable $error) {
    try {
        file_put_contents(Environment::privateRoot().'/server-diagnostic.json', json_encode([
            'type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine(),
        ], JSON_THROW_ON_ERROR));
    } catch (Throwable) {
        // A malformed ownership/path must not create a diagnostic elsewhere.
    }
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'Owned document fixture could not be verified.';
}
