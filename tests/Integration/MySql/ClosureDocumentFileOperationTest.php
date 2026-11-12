<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\Feature\Documents\DocumentFileOperationAuthorizationTest;

class ClosureDocumentFileOperationTest extends DocumentFileOperationAuthorizationTest
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $db = $app['db']->connection();
        if (! $app->environment('testing') || getenv('MYSQL_TESTS_ENABLED') !== '1' || $app->configurationIsCached()
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'quality_platform_ci' || $db->getConfig('url')) {
            throw new RuntimeException('Closure file tests require the opted-in owned disposable MySQL database.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version');
        if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
            || (getenv('MVP_FIXTURE_MYSQL_UUID') && ! hash_equals(getenv('MVP_FIXTURE_MYSQL_UUID'), $actual->uuid))) {
            throw new RuntimeException('Closure MySQL actual identity mismatch.');
        }

        return $app;
    }
}
