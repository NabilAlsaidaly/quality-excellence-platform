<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\ManageDocumentFileOperations;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantIsolationSchemaContract;

class ClosureOperationIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
    }

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                foreach ($this->documentWorkspaceFixtures as $fixture) {
                    foreach (['document_file_operation_changes', 'document_file_operation_assignments'] as $table) {
                        DB::table($table)->where('organization_id', $fixture['organization']->id)->where('fixture_id', $fixture['proof']['fixture_id'])->delete();
                    }
                }
                $this->cleanupDocumentWorkspace();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_actual_mysql_rejects_invalid_expiry_cross_tenant_user_and_duplicate_reserved_version_scope(): void
    {
        $fixture = $this->documentFixture();
        $foreign = $this->documentFixture();
        $fixture['proof']['file_operation_scope'] = 'CLOSURE_FILES_LOCAL_001';
        $this->loginDocumentActor($fixture['issuer'], $fixture['organization']);
        $id = $this->inDocumentFixture($fixture, fn () => app(ManageDocumentFileOperations::class)->issue($fixture['issuer'], $fixture['editor'],
            'upload_real', strtolower((string) Str::ulid()), strtolower((string) Str::ulid())));
        $original = (array) DB::table('document_file_operation_assignments')->where('public_id', $id)->sole();
        foreach ([0, -1, 86399, 86401] as $seconds) {
            try {
                DB::table('document_file_operation_assignments')->where('id', $original['id'])
                    ->update(['expires_at' => date('Y-m-d H:i:s', strtotime($original['starts_at']) + $seconds)]);
                $this->fail('The database must enforce a positive exact 24-hour lifetime.');
            } catch (QueryException $error) {
                $this->assertSame(3819, $error->errorInfo[1]);
                $this->assertSame($original, (array) DB::table('document_file_operation_assignments')->where('id', $original['id'])->sole());
            }
        }
        foreach (['grantee_id', 'issuer_id'] as $column) {
            $crossTenant = $original;
            unset($crossTenant['id'], $crossTenant['version_scope']);
            $crossTenant['public_id'] = strtolower((string) Str::ulid());
            $crossTenant['version_public_id'] = strtolower((string) Str::ulid());
            $crossTenant[$column] = $foreign['editor']->id;
            try {
                DB::table('document_file_operation_assignments')->insert($crossTenant);
                $this->fail('Composite user references must reject another institution.');
            } catch (QueryException $error) {
                $this->assertSame(1452, $error->errorInfo[1]);
            }
        }
        $duplicate = $original;
        unset($duplicate['id'], $duplicate['version_scope']);
        $duplicate['public_id'] = strtolower((string) Str::ulid());
        try {
            DB::table('document_file_operation_assignments')->insert($duplicate);
            $this->fail('An unused reserved version cannot receive duplicate same-operation authority.');
        } catch (QueryException $error) {
            $this->assertSame(1062, $error->errorInfo[1]);
        }
        $this->assertDatabaseCount('document_file_operation_assignments', 1);
    }

    public function test_each_new_foreign_unique_check_and_generated_scope_is_independently_required_by_the_schema_gate(): void
    {
        $schema = TenantIsolationSchemaContract::read(DB::connection());
        $this->assertSame([], TenantIsolationSchemaContract::violations($schema));
        foreach (['document_file_operation_assignments', 'document_file_operation_changes', 'maturity_reassessment_operation_assignments',
            'maturity_reassessment_operation_changes', 'document_demo_availability_decisions'] as $table) {
            foreach ($schema[$table]['foreign_keys'] as $index => $foreign) {
                $changed = $schema;
                unset($changed[$table]['foreign_keys'][$index]);
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' FK '.$foreign['columns']);
            }
            foreach ($schema[$table]['indexes'] as $name => $key) {
                if (! $key['unique']) {
                    continue;
                }
                $changed = $schema;
                unset($changed[$table]['indexes'][$name]);
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' UNIQUE '.$key['columns']);
            }
            foreach ($schema[$table]['checks'] as $name => $check) {
                $changed = $schema;
                $changed[$table]['checks'][$name]['enforced'] = false;
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' disabled CHECK '.$name);
                $changed[$table]['checks'][$name] = ['enforced' => true, 'clause' => '1=1'];
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' changed CHECK '.$name);
            }
            foreach ($schema[$table]['generated'] ?? [] as $name => $generation) {
                $changed = $schema;
                $changed[$table]['generated'][$name]['expression'] = '0';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.' generated '.$name);
            }
        }
    }
}
