<?php

namespace Tests\Integration\MySql;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class ClosureMigrationSafetyTest extends \Tests\Feature\Documents\ClosureMigrationSafetyTest
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $db = $app['db']->connection();
        $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version');
        if (! $app->environment('testing') || getenv('MYSQL_TESTS_ENABLED') !== '1' || $app->configurationIsCached()
            || $db->getDriverName() !== 'mysql' || $actual->name !== 'quality_platform_ci' || $db->getConfig('url')
            || ! str_starts_with($actual->version, '8.4.') || (getenv('MVP_FIXTURE_MYSQL_UUID') && ! hash_equals(getenv('MVP_FIXTURE_MYSQL_UUID'), $actual->uuid))) {
            throw new RuntimeException('Closure recovery requires the verified opted-in disposable MySQL target.');
        }

        return $app;
    }

    public function test_actual_create_denial_compensates_only_owned_keys_and_allows_a_reviewed_retry(): void
    {
        $file = '2026_11_14_000006_create_document_demo_availability_decisions_table.php';
        $this->artisan('migrate:rollback', ['--path' => ['database/migrations/'.$file], '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $journal = DB::table('migrations')->orderBy('id')->get()->toJson();
        $tables = Schema::getTableListing();
        $user = 'qep_closure_recovery_'.bin2hex(random_bytes(5));
        $password = bin2hex(random_bytes(24));
        $originalConnection = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        $attemptedCreate = false;
        // MySQL account-management DDL does not accept prepared parameter markers.
        DB::unprepared("CREATE USER '$user'@'%' IDENTIFIED BY ".DB::getPdo()->quote($password));
        try {
            DB::statement("GRANT SELECT ON quality_platform_ci.* TO '$user'@'%'");
            foreach (['document_intakes', 'document_file_operation_assignments'] as $table) {
                DB::statement("GRANT ALTER, INDEX, CREATE, INSERT, REFERENCES ON quality_platform_ci.`$table` TO '$user'@'%'");
            }
            config(['database.connections.closure_denied_create' => array_replace(DB::connection()->getConfig(), ['username' => $user, 'password' => $password])]);
            DB::setDefaultConnection('closure_denied_create');
            Schema::swap(DB::connection()->getSchemaBuilder());
            DB::connection()->beforeExecuting(function (string $sql) use (&$attemptedCreate): void {
                if (preg_match('/\ACREATE TABLE document_demo_availability_decisions\b/i', $sql)) {
                    $attemptedCreate = true;
                    $this->assertTrue(Schema::hasIndex('document_intakes', 'intake_demo_exact_scope'));
                    $this->assertTrue(Schema::hasIndex('document_file_operation_assignments', 'df_grant_demo_authority'));
                }
            });
            try {
                (require database_path('migrations/'.$file))->up();
                $this->fail('Actual CREATE denial must be visible and cannot journal a partially created package.');
            } catch (QueryException $error) {
                $this->assertSame(1142, $error->errorInfo[1], 'Actual MySQL permission denial, not a synthetic injected exception.');
                $this->assertTrue($attemptedCreate, 'The parent keys were created; CREATE itself must fail and exercise compensation.');
                $this->assertStringContainsString('CREATE command denied', $error->errorInfo[2]);
            }
        } finally {
            DB::setDefaultConnection($originalConnection);
            Schema::swap($originalSchema);
            DB::purge('closure_denied_create');
            config(['database.connections.closure_denied_create' => null]);
            DB::statement("DROP USER '$user'@'%'");
        }
        $this->assertSame($journal, DB::table('migrations')->orderBy('id')->get()->toJson());
        $this->assertSame($tables, Schema::getTableListing());
        $this->assertFalse(Schema::hasIndex('document_intakes', 'intake_demo_exact_scope'));
        $this->assertFalse(Schema::hasIndex('document_file_operation_assignments', 'df_grant_demo_authority'));
        $this->assertTrue(Schema::hasIndex('documents', 'documents_closure_public_scope'), 'Earlier approved keys must remain intact.');
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertDatabaseHas('migrations', ['migration' => pathinfo($file, PATHINFO_FILENAME)]);
        $this->assertTrue(Schema::hasTable('document_demo_availability_decisions'));
    }
}
