<?php

namespace Tests\Support;

use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\TestCase;

abstract class MySqlTestCase extends TestCase
{
    public function createApplication(): Application
    {
        if (file_exists(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
            throw new RuntimeException('Clear cached configuration before integration tests.');
        }

        $app = parent::createApplication();
        $connection = $app['db']->connection();

        // Validate before any RefreshDatabase/DatabaseMigrations hook can run.
        if (getenv('MYSQL_TESTS_ENABLED') !== '1' || ! $app->environment('testing')
            || $connection->getDriverName() !== 'mysql'
            || $connection->getDatabaseName() !== 'quality_platform_ci'
            || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('MySQL tests require explicit opt-in and disposable quality_platform_ci in testing.');
        }

        if ($connection->selectOne('SELECT DATABASE() AS name')->name !== 'quality_platform_ci') {
            throw new RuntimeException('Actual MySQL database is not the approved disposable schema.');
        }

        return $app;
    }
}
