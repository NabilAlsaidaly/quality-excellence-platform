<?php

namespace Tests\Browser\ReviewMaturity;

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\ReviewMaturityPanelProvider;

/** Separate owned test application, with no normal .env/provider/panel changes. */
final class Environment
{
    public static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    public static function privateRoot(): string
    {
        $run = (string) getenv('QEP_REVIEW_MATURITY_RUN');
        if (! preg_match('/\Abrowser-[0-9]{2}\z/', $run)) {
            throw new RuntimeException('Explicit owned document run required.');
        }

        return self::root().'/.local-tools/reports/issue62/review-maturity/'.$run;
    }

    public static function configuration(): array
    {
        $private = self::privateRoot();
        $id = (string) getenv('QEP_REVIEW_MATURITY_FIXTURE');
        if (! preg_match('/\A[a-f0-9]{32}\z/', $id) || realpath($private) === false
            || strcasecmp(str_replace('\\', '/', realpath($private)), str_replace('\\', '/', $private)) !== 0) {
            throw new RuntimeException('Owned canonical document fixture path required.');
        }
        $c = json_decode(file_get_contents($private.'/connection.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($c['namespace'] !== 'qep-issue62-review-maturity-browser' || ! hash_equals($c['fixture_id'], $id)
            || $c['run_name'] !== getenv('QEP_REVIEW_MATURITY_RUN') || $c['database'] !== 'quality_platform_ci'
            || $c['http_host'] !== '127.0.0.6' || ! is_int($c['db_port']) || $c['db_port'] < 1 || $c['db_port'] > 65535
            || ! preg_match('/\A[a-f0-9-]{36}\z/i', $c['server_uuid'])) {
            throw new RuntimeException('Refuse unverified document fixture configuration.');
        }

        return $c;
    }

    public static function boot(bool $operator = false, bool $http = false): array
    {
        $c = self::configuration();
        $private = self::privateRoot();
        foreach ($c['source_hashes'] as $path => $hash) {
            if (! hash_equals($hash, hash_file('sha256', self::root().'/'.$path))) {
                throw new RuntimeException('Reviewed document source changed.');
            }
        }
        $values = ['APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $c['app_key'],
            'APP_URL' => 'http://'.$c['http_host'].':'.$c['http_port'], 'APP_LOCALE' => 'ar', 'BCRYPT_ROUNDS' => '4',
            'APP_CONFIG_CACHE' => $private.'/config.php', 'APP_ROUTES_CACHE' => $private.'/routes.php', 'VIEW_COMPILED_PATH' => $private.'/views',
            'DB_URL' => '', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'quality_platform_ci', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $c['db_port'],
            'DB_USERNAME' => $operator ? 'root' : 'workflow_fixture',
            'DB_PASSWORD' => $operator ? file_get_contents($private.'/mysql-root-password') : $c['runtime_password'],
            'CACHE_STORE' => 'file', 'SESSION_DRIVER' => 'file', 'SESSION_COOKIE' => 'qep_review_maturity_'.$c['fixture_id'],
            'SESSION_DOMAIN' => '', 'SESSION_SECURE_COOKIE' => 'false', 'SESSION_SAME_SITE' => 'lax',
            'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'null'];
        foreach ($values as $key => $value) {
            putenv($key.'='.$value);
            $_ENV[$key] = $_SERVER[$key] = $value;
        }
        chdir(self::root());
        require_once self::root().'/vendor/autoload.php';
        $app = require self::root().'/bootstrap/app.php';
        $app->booting(function () use ($app, $private): void {
            config(['session.files' => $private.'/sessions', 'cache.stores.file.path' => $private.'/cache', 'cache.stores.file.lock_path' => $private.'/locks',
                'filesystems.disks.local.root' => $private.'/files', 'filesystems.disks.local.serve' => false]);
            $app->register(ReviewMaturityPanelProvider::class);
        });
        $kernel = $app->make($http ? HttpKernel::class : ConsoleKernel::class);
        $kernel->bootstrap();
        $db = DB::connection();
        $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version');
        if (! $app->environment('testing') || $db->getDriverName() !== 'mysql' || $actual->name !== 'quality_platform_ci'
            || ! hash_equals($c['server_uuid'], $actual->uuid) || ! str_starts_with($actual->version, '8.4.')
            || $app->configurationIsCached() || $app->routesAreCached() || config('database.connections.mysql.url')) {
            throw new RuntimeException('Actual isolated document database/environment mismatch.');
        }

        return [$app, $kernel, $db, $c];
    }

    public static function fixture(array $configuration): array
    {
        $fixture = json_decode(file_get_contents(self::privateRoot().'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
        if ($fixture['owner'] !== $configuration['fixture_id'] || $fixture['panel_proof']['workflow']['server_uuid'] !== $configuration['server_uuid']) {
            throw new RuntimeException('Document fixture ownership mismatch.');
        }

        return $fixture;
    }
}
