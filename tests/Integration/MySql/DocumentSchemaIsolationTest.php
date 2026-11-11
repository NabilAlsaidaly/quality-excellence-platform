<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\AppendDocumentVersion;
use App\Actions\Documents\CreateDocumentWithVersion;
use App\Authorization\TenantContext;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\ApproachAssignmentFixtures;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantIsolationSchemaContract;

trait DocumentMySqlFixtures
{
    private array $ownedDocuments = [];

    protected function prepareDocumentFixtures(): void
    {
        Storage::set('local', Storage::fake('documents-'.strtolower((string) Str::ulid())));
        Event::listen('eloquent.created: '.Document::class, function (Document $document): void {
            $this->ownedDocuments[] = $document->id;
        });
    }

    protected function cleanupDocumentFixtures(): void
    {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if (DB::connection()->getDatabaseName() !== 'quality_platform_ci' || getenv('MYSQL_TESTS_ENABLED') !== '1') {
            throw new RuntimeException('Refuse cleanup outside disposable document test schema.');
        }
        if (Schema::hasTable('document_versions')) {
            foreach (DB::table('document_versions')->whereIn('document_id', $this->ownedDocuments)->get() as $version) {
                Storage::disk('local')->delete($version->storage_key);
            }
            DB::table('document_versions')->whereIn('document_id', $this->ownedDocuments)->delete();
        }
        if (Schema::hasTable('documents')) {
            DB::table('documents')->whereIn('id', $this->ownedDocuments)->delete();
        }
    }

    protected function documentMember(): OrganizationMembership
    {
        return OrganizationMembership::factory()->create();
    }

    protected function trustedDocument(OrganizationMembership $member, callable $operation): mixed
    {
        return app(TenantContext::class)->run($member->user, $member->organization_id, $operation(...));
    }

    protected function documentStream(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }

    protected function createDocumentFixture(OrganizationMembership $member): array
    {
        $stream = $this->documentStream('historical v1');
        try {
            return $this->trustedDocument($member, fn () => app(CreateDocumentWithVersion::class)->execute($member->user,
                ['title' => 'وثيقة اختبار MySQL', 'description' => 'وصف', 'original_filename' => 'synthetic.bin'], $stream));
        } finally {
            fclose($stream);
        }
    }

    protected function sqlFailure(callable $operation, int $code): void
    {
        try {
            $operation();
            $this->fail('Actual MySQL must reject the invalid statement.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1], $error->getMessage());
        }
    }
}

class DocumentSchemaIsolationTest extends MySqlTestCase
{
    use ApproachAssignmentFixtures, DatabaseMigrations, DocumentMySqlFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentFixtures();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentFixtures();
        parent::tearDown();
    }

    private const MIGRATION_INVENTORY = [
        '0001_01_01_000000_create_users_table.php',
        '0001_01_01_000001_create_cache_table.php',
        '0001_01_01_000002_create_jobs_table.php',
        '2026_10_31_000001_create_organizations_table.php',
        '2026_10_31_000002_create_organization_memberships_table.php',
        '2026_10_31_000003_create_organizational_units_table.php',
        '2026_11_03_000001_create_permission_permissions_table.php',
        '2026_11_03_000002_create_permission_roles_table.php',
        '2026_11_03_000003_create_permission_role_has_permissions_table.php',
        '2026_11_03_000004_create_permission_model_has_roles_table.php',
        '2026_11_03_000005_create_permission_model_has_permissions_table.php',
        '2026_11_03_000006_create_permission_authorization_changes_table.php',
        '2026_11_03_000007_create_organizational_unit_changes_table.php',
        '2026_11_03_000008_create_organization_changes_table.php',
        '2026_11_05_000001_expand_user_identity_namespaces.php',
        '2026_11_05_000002_guard_identity_membership_insert.php',
        '2026_11_05_000003_guard_identity_membership_update.php',
        '2026_11_05_000004_guard_identity_binding_update.php',
        '2026_11_05_000005_create_institution_password_reset_tokens.php',
        '2026_11_07_000001_create_approaches_table.php',
        '2026_11_07_000002_create_approach_content_revisions_table.php',
        '2026_11_08_000001_create_approach_changes_table.php',
        '2026_11_08_000002_create_documents_table.php',
        '2026_11_08_000003_create_document_versions_table.php',
        '2026_11_10_000001_create_approach_assignments_table.php',
        '2026_11_10_000002_create_approach_assignment_changes_table.php',
        '2026_11_11_000001_create_document_workspace_assignments_table.php',
        '2026_11_11_000002_create_document_workspace_assignment_changes_table.php',
        '2026_11_11_000003_create_document_metadata_revisions_table.php',
        '2026_11_12_000001_add_approach_revision_workflow_candidate_key.php',
        '2026_11_12_000002_add_document_version_workflow_candidate_key.php',
        '2026_11_12_000003_create_maturity_workflow_stages_table.php',
        '2026_11_12_000004_create_review_maturity_assignments_table.php',
        '2026_11_12_000005_create_maturity_workflow_configurations_table.php',
        '2026_11_12_000006_create_maturity_workflow_submissions_table.php',
        '2026_11_12_000007_create_maturity_workflow_evidence_table.php',
        '2026_11_12_000008_create_review_maturity_reviews_table.php',
        '2026_11_12_000009_create_maturity_workflow_assessments_table.php',
        '2026_11_12_000010_create_review_maturity_audit_table.php',
        '2026_11_12_000011_create_review_maturity_notifications_table.php',
        '2026_11_13_000001_add_reassessment_parent_key.php',
        '2026_11_13_000002_create_document_intakes_table.php',
        '2026_11_13_000003_create_document_intake_events_table.php',
        '2026_11_13_000004_create_maturity_reassessment_attempts_table.php',
        '2026_11_13_000005_create_maturity_reassessment_decisions_table.php',
        '2026_11_13_000006_create_maturity_reassessment_events_table.php',
    ];

    public function test_measured_schema_journal_and_strict_negative_contract_match_actual_mysql(): void
    {
        $actualMigrations = array_map('basename', glob(database_path('migrations/*.php')));
        sort($actualMigrations);
        $this->assertSame(count(self::MIGRATION_INVENTORY), count($actualMigrations));
        $this->assertSame(self::MIGRATION_INVENTORY, $actualMigrations);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
        $schema = TenantIsolationSchemaContract::read(DB::connection());
        $this->assertCount(count(TenantIsolationSchemaContract::TABLES), $schema);
        $this->assertSame([], TenantIsolationSchemaContract::violations($schema));
        $this->assertSame('organization_id,created_at,id', $schema['documents']['indexes']['documents_organization_created_idx']['columns']);
        foreach (['documents', 'document_versions'] as $table) {
            $this->assertSame('direct', TenantIsolationSchemaContract::TABLES[$table]);
            foreach ($schema[$table]['foreign_keys'] as $name => $key) {
                $changed = $schema;
                unset($changed[$table]['foreign_keys'][$name]);
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
                $changed = $schema;
                $changed[$table]['foreign_keys'][$name]['delete'] = 'CASCADE';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
            foreach ($schema[$table]['indexes'] as $name => $key) {
                if ($key['unique']) {
                    $changed = $schema;
                    unset($changed[$table]['indexes'][$name]);
                    $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
                }
            }
            foreach ($schema[$table]['columns'] as $column => $type) {
                if (! $type['nullable']) {
                    $changed = $schema;
                    $changed[$table]['columns'][$column]['nullable'] = true;
                    $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.'.'.$column);
                }
            }
        }
        foreach ($schema['document_versions']['checks'] as $name => $check) {
            foreach (['enforced' => false, 'clause' => '1'] as $key => $value) {
                $changed = $schema;
                $changed['document_versions']['checks'][$name][$key] = $value;
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
        }
    }

    public function test_raw_cross_tenant_references_unique_keys_positive_range_and_storage_checks_are_enforced(): void
    {
        $a = $this->documentMember();
        $b = $this->documentMember();
        $first = $this->createDocumentFixture($a);
        $foreign = $this->createDocumentFixture($b);
        $version = DocumentVersion::query()->where('public_id', $first['version_public_id'])->firstOrFail();
        $otherDocument = Document::query()->where('public_id', $foreign['document_public_id'])->firstOrFail();
        $copy = $version->getRawOriginal();
        unset($copy['id']);
        $copy['public_id'] = strtolower((string) Str::ulid());
        $copy['storage_key'] .= '-copy';
        $copy['version_number'] = 2;
        $this->sqlFailure(fn () => DB::table('document_versions')->insert(array_replace($copy, ['document_id' => $otherDocument->id])), 1452);
        $this->sqlFailure(fn () => DB::table('document_versions')->where('id', $version->id)->update(['organization_id' => $b->organization_id]), 1452);
        foreach (['version_number' => 0, 'sha256' => str_repeat('A', 64), 'storage_disk' => 'public'] as $field => $value) {
            $this->sqlFailure(fn () => DB::table('document_versions')->insert(array_replace($copy, [$field => $value])), 3819);
        }
        $this->sqlFailure(fn () => DB::table('document_versions')->insert(array_replace($copy, ['byte_size' => -1])), 1264);
        $this->sqlFailure(fn () => DB::table('document_versions')->insert(array_replace($copy, ['version_number' => 4294967296])), 1264);
        foreach (['public_id' => $version->public_id, 'storage_key' => $version->storage_key, 'version_number' => 1] as $field => $value) {
            $this->sqlFailure(fn () => DB::table('document_versions')->insert(array_replace($copy, [$field => $value])), 1062);
        }
        $document = $version->document;
        $documentCopy = $document->getRawOriginal();
        unset($documentCopy['id']);
        $this->sqlFailure(fn () => DB::table('documents')->insert($documentCopy), 1062);
        $this->sqlFailure(fn () => DB::table('documents')->where('id', $document->id)->delete(), 1451);
        $this->sqlFailure(fn () => DB::table('organizations')->where('id', $a->organization_id)->delete(), 1451);
        $a->delete();
        $this->sqlFailure(fn () => DB::table('users')->where('id', $a->user_id)->delete(), 1451);
        $this->assertSame('historical v1', Storage::disk('local')->get($version->storage_key));
    }

    public function test_empty_rollback_reapply_and_each_atomic_fk_failure_leave_no_partial_table(): void
    {
        $this->rollbackEmptyPersistenceMigrations();
        $this->rollbackEmptyDocumentWorkspaceMigrations();
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_11_08_000002_create_documents_table.php',
            'database/migrations/2026_11_08_000003_create_document_versions_table.php',
        ], '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertSame(24, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasTable('documents'));
        DB::statement('RENAME TABLE organizations TO documents_root_fixture');
        try {
            $migration = require database_path('migrations/2026_11_08_000002_create_documents_table.php');
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('documents'));
        } finally {
            DB::statement('RENAME TABLE documents_root_fixture TO organizations');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->rollbackEmptyPersistenceMigrations();
        $this->rollbackEmptyReviewMaturityMigrations();
        $this->artisan('migrate:rollback', ['--path' => ['database/migrations/2026_11_08_000003_create_document_versions_table.php'],
            '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertSame(28, DB::table('migrations')->count());
        DB::statement('RENAME TABLE documents TO versions_parent_fixture');
        try {
            $migration = require database_path('migrations/2026_11_08_000003_create_document_versions_table.php');
            $this->sqlFailure(fn () => $migration->up(), 1824);
            $this->assertFalse(Schema::hasTable('document_versions'));
        } finally {
            DB::statement('RENAME TABLE versions_parent_fixture TO documents');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
        $migration = require database_path('migrations/2026_11_08_000003_create_document_versions_table.php');
        $this->sqlFailure(fn () => $migration->up(), 1050);
        $this->assertTrue(Schema::hasTable('document_versions'));
    }

    public function test_identity_only_and_versioned_populated_rollback_refuse_before_first_drop(): void
    {
        $member = $this->documentMember();
        $document = new Document;
        $document->forceFill(['organization_id' => $member->organization_id, 'created_by' => $member->user_id, 'title' => 'identity only', 'description' => 'protected']);
        $document->save();
        foreach (['2026_11_08_000003_create_document_versions_table.php', '2026_11_08_000002_create_documents_table.php'] as $path) {
            try {
                (require database_path('migrations/'.$path))->down();
                $this->fail('Identity alone must block destructive rollback.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
                $this->assertTrue(Schema::hasTable('document_versions'));
                $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
            }
        }
        $this->createDocumentFixture($member);
        try {
            $this->artisan('migrate:rollback', ['--path' => ['database/migrations/2026_11_08_000003_create_document_versions_table.php'],
                '--step' => DB::table('migrations')->count(), '--force' => true]);
            $this->fail('Populated first DROP must refuse.');
        } catch (RuntimeException) {
            $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
            $this->assertDatabaseCount('document_versions', 1);
        }
    }

    public static function mysqlInsertFaults(): array
    {
        return [['creating'], ['created']];
    }

    #[DataProvider('mysqlInsertFaults')]
    public function test_mysql_insert_exception_rolls_back_rows_and_only_new_blob(string $phase): void
    {
        $member = $this->documentMember();
        $first = $this->createDocumentFixture($member);
        $original = DocumentVersion::sole()->fresh()->getAttributes();
        $before = Storage::disk('local')->allFiles();
        $armed = true;
        Event::listen('eloquent.'.$phase.': '.DocumentVersion::class, function () use (&$armed): void {
            if ($armed) {
                throw new RuntimeException('Synthetic MySQL version fault.');
            }
        });
        $stream = $this->documentStream('new attempt');
        try {
            $this->trustedDocument($member, fn () => app(AppendDocumentVersion::class)->execute($member->user, $first['document_public_id'], ['original_filename' => 'new.bin'], $stream));
            $this->fail('Fault must propagate.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic MySQL version fault.', $error->getMessage());
            $this->assertSame($original, DocumentVersion::sole()->getAttributes());
            $this->assertSame($before, Storage::disk('local')->allFiles());
            $this->assertSame(0, DB::transactionLevel());
        } finally {
            fclose($stream);
            $armed = false;
        }
    }

    private function rollbackEmptyPersistenceMigrations(): void
    {
        $paths = [
            'database/migrations/2026_11_13_000001_add_reassessment_parent_key.php',
            'database/migrations/2026_11_13_000002_create_document_intakes_table.php',
            'database/migrations/2026_11_13_000003_create_document_intake_events_table.php',
            'database/migrations/2026_11_13_000004_create_maturity_reassessment_attempts_table.php',
            'database/migrations/2026_11_13_000005_create_maturity_reassessment_decisions_table.php',
            'database/migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php',
        ];
        $stems = array_map(fn ($path) => basename($path, '.php'), $paths);
        if (DB::table('migrations')->whereIn('migration', $stems)->exists()) {
            // New guards independently refuse populated, drifted or unjournaled state.
            $this->artisan('migrate:rollback', ['--path' => $paths,
                '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        }
    }
}
