<?php

use App\Models\User;
use App\Notifications\InstitutionPasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Tests\Browser\InstitutionIdentity\Environment;

ini_set('display_errors', '0');
ini_set('log_errors', '0');
require_once __DIR__.'/Environment.php';
try {
    $config = Environment::configuration();
    if (PHP_SAPI !== 'cli-server' || ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.1'
        && ($_SERVER['REMOTE_ADDR'] ?? '') !== '127.0.0.3'
        || ($_SERVER['HTTP_HOST'] ?? '') !== $config['http_host'].':'.$config['http_port']) {
        http_response_code(403);
        exit('Local test only.');
    }
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $file = realpath(Environment::root().'/public/'.ltrim(rawurldecode($uri), '/'));
    if (preg_match('~^/(build/|fonts/filament/|js/filament/|css/filament/|favicon\.ico$)~', $uri)
        && preg_match('~\.(css|js|woff2?|ttf|svg|png|ico|webp)$~i', $uri)
        && $file && str_starts_with(str_replace('\\', '/', $file), str_replace('\\', '/', Environment::root()).'/public/') && is_file($file)) {
        return false;
    }
    if (! str_starts_with($uri, '/institution/')) {
        http_response_code(404);
        exit('Not part of the identity journey.');
    }
    [$app, $kernel] = Environment::boot(http: true);
    Notification::fake(); // Only outbound test delivery; real broker/tokens/guards/Policies remain.
    $request = Request::capture();
    $response = $kernel->handle($request);
    $fixture = json_decode(file_get_contents(Environment::privateRoot().'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ($fixture['accounts'] as $index => $record) {
        $notices = Notification::sent(User::findOrFail($record['id']), InstitutionPasswordReset::class);
        if ($notices->isNotEmpty()) {
            file_put_contents(Environment::privateRoot().'/reset-'.$index.'.txt', $notices->last()->url(), LOCK_EX);
        }
    }
    $response->send();
    $kernel->terminate($request, $response);
} catch (Throwable $error) {
    http_response_code(503);
    header('Cache-Control: no-store');
    echo 'Identity test environment could not be verified.';
}
