<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Application;
use RuntimeException;

class ClosureFileAtomicityTest extends \Tests\Feature\Documents\ClosureFileAtomicityTest
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $db = $app['db']->connection();
        if (! $app->environment('testing') || getenv('MYSQL_TESTS_ENABLED') !== '1' || $db->getDriverName() !== 'mysql'
            || $db->getDatabaseName() !== 'quality_platform_ci' || $db->getConfig('url') || $app->configurationIsCached()) {
            throw new RuntimeException('Owned opted-in disposable MySQL required.');
        }
        $actual = $db->selectOne('SELECT VERSION() AS version,@@server_uuid AS uuid');
        if (! str_starts_with($actual->version, '8.4.') || (getenv('MVP_FIXTURE_MYSQL_UUID') && ! hash_equals(getenv('MVP_FIXTURE_MYSQL_UUID'), $actual->uuid))) {
            throw new RuntimeException('Wrong MySQL identity.');
        }

        return $app;
    }
}
