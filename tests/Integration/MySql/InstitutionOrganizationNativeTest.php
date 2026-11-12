<?php

namespace Tests\Integration\MySql;

use Illuminate\Foundation\Application;
use RuntimeException;
use Tests\Feature\Filament\InstitutionOrganizationIntegrationTest;

/** Run the exact signed HTTP/native assertions against guarded actual MySQL. */
class InstitutionOrganizationNativeTest extends InstitutionOrganizationIntegrationTest
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $db = $app['db']->connection();
        // createApplication precedes DatabaseMigrations: refuse before any DDL.
        if (getenv('MYSQL_TESTS_ENABLED') !== '1' || ! $app->environment('testing')
            || $db->getDriverName() !== 'mysql' || $db->getDatabaseName() !== 'quality_platform_ci'
            || $app->configurationIsCached() || $app['config']->get('database.connections.mysql.url')) {
            throw new RuntimeException('Native MySQL assertions require the opted-in disposable database.');
        }
        $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version');
        $expected = getenv('MVP_FIXTURE_MYSQL_UUID');
        if ($actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
            || ($expected !== false && $expected !== '' && ! hash_equals($expected, $actual->uuid))) {
            throw new RuntimeException('Actual native MySQL server identity mismatch.');
        }

        return $app;
    }
}
