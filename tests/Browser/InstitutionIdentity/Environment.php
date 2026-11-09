<?php

namespace Tests\Browser\InstitutionIdentity;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Local J01 test fixture boundary; never used by application providers/routes. */
final class Environment
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function privateRoot(): string
    {
        return self::root().'/.local-tools/reports/issue53';
    }

    public static function assertManifest(array $config, string $optIn): void
    {
        if (($config['namespace'] ?? null) !== 'qep-issue53-identity'
            || ! preg_match('/\A[a-f0-9]{16}\z/', $config['session'] ?? '')
            || ! hash_equals($config['session'], $optIn)
            || ($config['database'] ?? null) !== 'quality_platform_ci'
            || ($config['db_host'] ?? null) !== '127.0.0.1'
            || ($config['http_host'] ?? null) !== '127.0.0.3'
            || ! is_int($config['db_port'] ?? null) || $config['db_port'] < 1 || $config['db_port'] > 65535
            || ! is_int($config['http_port'] ?? null) || $config['http_port'] < 1024 || $config['http_port'] > 65535
            || ! preg_match('/\A[a-f0-9-]{36}\z/i', $config['server_uuid'] ?? '')) {
            throw new RuntimeException('Refuse an unverified identity journey target.');
        }
    }

    public static function assertDatabase(array $config, object $actual, string $environment, string $driver): void
    {
        if ($environment !== 'testing' || $driver !== 'mysql' || $actual->name !== 'quality_platform_ci'
            || ! hash_equals($config['server_uuid'], $actual->uuid) || ! str_starts_with($actual->version, '8.4.')) {
            throw new RuntimeException('Actual identity journey environment/database mismatch.');
        }
    }

    public static function configuration(): array
    {
        if (! preg_match('/\A[a-f0-9]{16}\z/', (string) getenv('QEP_IDENTITY_JOURNEY'))) {
            throw new RuntimeException('Explicit identity journey opt-in is missing.');
        }
        $root = self::privateRoot();
        if (realpath($root) === false || strcasecmp(str_replace('\\', '/', realpath($root)), str_replace('\\', '/', $root)) !== 0) {
            throw new RuntimeException('Refuse redirected private fixture directory.');
        }
        $config = json_decode(file_get_contents($root.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertManifest($config, (string) getenv('QEP_IDENTITY_JOURNEY'));

        return $config;
    }

    public static function boot(bool $operator = false, bool $http = false): array
    {
        $config = self::configuration();
        $private = self::privateRoot();
        chdir(self::root());
        foreach ($config['source_hashes'] as $path => $hash) {
            if (! hash_equals($hash, hash_file('sha256', self::root().'/'.$path))) {
                throw new RuntimeException('Reviewed identity source changed.');
            }
        }
        $values = [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $config['app_key'],
            'APP_URL' => 'http://'.$config['http_host'].':'.$config['http_port'], 'APP_LOCALE' => 'ar',
            'APP_CONFIG_CACHE' => $private.'/config.php', 'APP_ROUTES_CACHE' => $private.'/routes.php',
            'VIEW_COMPILED_PATH' => $private.'/views', 'DB_URL' => '', 'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $config['db_port'], 'DB_DATABASE' => 'quality_platform_ci',
            'DB_USERNAME' => $operator ? 'root' : 'identity_journey',
            'DB_PASSWORD' => $operator ? file_get_contents($private.'/mysql-root-password') : $config['runtime_password'],
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'qep_identity_'.$config['session'],
            'SESSION_DOMAIN' => '', 'SESSION_LIFETIME' => '1', 'SESSION_SECURE_COOKIE' => 'false',
            'SESSION_SAME_SITE' => 'lax', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null',
        ];
        foreach ($values as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        require_once self::root().'/vendor/autoload.php';
        $app = require self::root().'/bootstrap/app.php';
        $kernel = $app->make($http ? HttpKernel::class : ConsoleKernel::class);
        $kernel->bootstrap();
        config(['session.files' => $private.'/sessions', 'cache.stores.file.path' => $private.'/cache',
            'cache.stores.file.lock_path' => $private.'/cache-locks']);
        $db = DB::connection();
        self::assertDatabase($config, $db->selectOne('SELECT DATABASE() AS name, @@server_uuid AS uuid, VERSION() AS version'), $app->environment(), $db->getDriverName());
        if ($app->configurationIsCached() || $app->routesAreCached() || config('database.connections.mysql.url')) {
            throw new RuntimeException('Refuse cached or URL-overridden identity test configuration.');
        }

        return [$app, $kernel, $db, $config];
    }
}
