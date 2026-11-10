<?php

namespace Tests\Integration\MySql;

use App\Actions\Documents\DocumentWorkspace;
use App\Models\DocumentMetadataRevision;
use App\Models\DocumentVersion;
use App\Models\DocumentWorkspaceAssignment;
use App\Models\DocumentWorkspaceAssignmentChange;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\Support\MySqlTestCase;
use Tests\Support\TenantIsolationSchemaContract;

class DocumentWorkspaceIntegrityTest extends MySqlTestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

    private const PATHS = [
        'database/migrations/2026_11_11_000001_create_document_workspace_assignments_table.php',
        'database/migrations/2026_11_11_000002_create_document_workspace_assignment_changes_table.php',
        'database/migrations/2026_11_11_000003_create_document_metadata_revisions_table.php',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepareDocumentWorkspace();
    }

    protected function tearDown(): void
    {
        $this->cleanupDocumentWorkspace();
        parent::tearDown();
    }

    private function rejected(callable $operation, int $code): void
    {
        DB::beginTransaction();
        try {
            $operation();
            $this->fail('Invalid document workspace SQL data accepted.');
        } catch (QueryException $error) {
            $this->assertSame($code, $error->errorInfo[1]);
        } finally {
            DB::rollBack();
        }
    }

    public function test_actual_mysql_schema_has_explicit_ordered_user_keys_longtext_and_strict_expiry(): void
    {
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is required.');
        }
        foreach (['document_workspace_assignments' => ['grantee_id', 'issuer_id', 'revoked_by'],
            'document_workspace_assignment_changes' => ['actor_id', 'subject_id'],
            'document_metadata_revisions' => ['actor_id']] as $table => $fields) {
            $fks = collect(DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_COLUMN_NAME, ORDINAL_POSITION FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME = 'users'", [$table]))->groupBy('CONSTRAINT_NAME');
            foreach ($fields as $field) {
                $fk = $fks->first(fn ($rows) => $rows->first()->COLUMN_NAME === $field);
                $this->assertNotNull($fk);
                $this->assertSame([$field, 'organization_id'], $fk->sortBy('ORDINAL_POSITION')->pluck('COLUMN_NAME')->all());
                $this->assertSame(['id', 'organization_id'], $fk->sortBy('ORDINAL_POSITION')->pluck('REFERENCED_COLUMN_NAME')->all());
            }
        }
        $column = DB::selectOne("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='document_metadata_revisions' AND COLUMN_NAME='description'");
        $this->assertSame('longtext', $column->DATA_TYPE);
        $create = DB::selectOne('SHOW CREATE TABLE document_workspace_assignments')->{'Create Table'};
        $this->assertStringContainsString('`expires_at` > `starts_at`', $create);
        $this->assertSame(count(glob(database_path('migrations/*.php'))), DB::table('migrations')->count());
    }

    public function test_mysql_exact_24_hours_invalid_dates_overflow_and_valid_upper_boundary(): void
    {
        $f = $this->documentFixture();
        $row = (array) DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->first();
        unset($row['id'], $row['scope_key']);
        foreach ([['2026-11-10 00:00:00', '2026-11-10 00:00:00'], ['2026-11-10 00:00:00', '2026-11-09 00:00:00'],
            ['2026-11-10 00:00:00', '2026-11-10 23:59:59'], ['2026-11-10 00:00:00', '2026-11-11 00:00:01'],
            ['9999-12-31 00:00:00', '9999-12-31 23:59:59']] as [$start, $end]) {
            $copy = array_replace($row, ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), 'starts_at' => $start, 'expires_at' => $end]);
            $this->rejected(fn () => DB::table('document_workspace_assignments')->insert($copy), 3819);
        }
        foreach (['not-a-date', '0000-00-00 00:00:00'] as $invalid) {
            $this->rejected(fn () => DB::table('document_workspace_assignments')->insert(array_replace($row,
                ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), 'starts_at' => $invalid])), 1292);
        }
        $this->rejected(fn () => DB::table('document_workspace_assignments')->insert(array_replace($row,
            ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), 'expires_at' => null])), 1048);
        DB::beginTransaction();
        try {
            DB::table('document_workspace_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid(),
                'fixture_id' => bin2hex(random_bytes(16)), 'starts_at' => '9999-12-30 23:59:59', 'expires_at' => '9999-12-31 23:59:59']));
            $this->assertSame('9999-12-31 23:59:59', DB::table('document_workspace_assignments')->orderByDesc('id')->value('expires_at'));
        } finally {
            DB::rollBack();
        }
    }

    public function test_mysql_real_policy_atomic_create_longtext_metadata_safe_assigned_pagination_and_versions(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $workspace = app(DocumentWorkspace::class);
            $original = DB::table('document_versions')->first();
            $workspace->updateMetadata($id, ['title' => 'بيانات جديدة', 'description' => str_repeat('ع', 65535)], 'none');
            $capacity = DB::selectOne('SELECT CHAR_LENGTH(description) AS characters, OCTET_LENGTH(description) AS bytes FROM document_metadata_revisions');
            $this->assertSame(65535, $capacity->characters);
            $this->assertSame(131070, $capacity->bytes);
            $token = $workspace->show($id)['metadata_token'];
            try {
                $workspace->updateMetadata($id, ['title' => 'تجاوز', 'description' => str_repeat('ع', 65536)], $token);
                $this->fail('Existing UTF8 character limit was widened.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('document_metadata_revisions', 1);
            }
            $workspace->append($id);
            $this->assertDatabaseCount('documents', 1);
            $this->assertDatabaseCount('document_versions', 2);
            $this->assertSame(6, DB::table('document_workspace_assignments')->whereNotNull('document_id')->count());
            $this->assertSame(6, DB::table('document_workspace_assignment_changes')->whereNotNull('document_id')->where('event', 'granted')->count());
            $this->assertSame($original->sha256, hash('sha256', Storage::disk('local')->get($original->storage_key)));
            $this->assertSame('وثيقة اختبار مؤسسية', DB::table('documents')->value('title'));
            $this->unassignedWorkspaceDocument($f);
            $this->loginDocumentActor($f['reader'], $f['organization']);
            $this->assertSame(1, $workspace->listing(perPage: 1)->total());
            $this->assertSame(0, $workspace->listing('غير مسندة')->total());
            foreach (['versions', 'metadata'] as $type) {
                $rows = $workspace->history($id, $type)->items();
                $this->assertNotEmpty($rows);
                foreach (['id', 'organization_id', 'document_id', 'actor_id', 'storage_key', 'sha256', 'original_filename'] as $key) {
                    $this->assertArrayNotHasKey($key, $rows[0]);
                }
            }
        });
    }

    public function test_mysql_raw_scope_uniqueness_tenant_and_exact_audit_attribution_are_enforced(): void
    {
        $f = $this->documentFixture();
        $foreign = $this->documentFixture();
        $this->inDocumentFixture($f, fn () => $this->createWorkspaceDocument($f));
        $this->inDocumentFixture($foreign, fn () => $this->createWorkspaceDocument($foreign));
        $row = (array) DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'view')->first();
        unset($row['id'], $row['scope_key']);
        $this->rejected(fn () => DB::table('document_workspace_assignments')->insert(array_replace($row, ['public_id' => (string) Str::ulid()])), 1062);
        foreach ([['classification' => 'OPERATIONAL'], ['scenario' => 'other'], ['scope_kind' => 'institution'],
            ['operation' => 'approve'], ['revoked_at' => $row['starts_at'], 'revoked_by' => null], ['fixture_id' => str_repeat('z', 32)]] as $invalid) {
            $this->rejected(fn () => DB::table('document_workspace_assignments')->insert(array_replace($row,
                ['public_id' => (string) Str::ulid(), 'grantee_id' => $f['unassigned']->id], $invalid)), 3819);
        }
        foreach (['grantee_id', 'issuer_id'] as $column) {
            $this->rejected(fn () => DB::table('document_workspace_assignments')->insert(array_replace($row,
                ['public_id' => (string) Str::ulid(), 'fixture_id' => bin2hex(random_bytes(16)), $column => $foreign['editor']->id])), 1452);
        }
        $audit = (array) DB::table('document_workspace_assignment_changes')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'view')->first();
        unset($audit['id'], $audit['scope_key']);
        foreach ([['fixture_id' => bin2hex(random_bytes(16))], ['subject_id' => $f['unassigned']->id], ['operation' => 'history'],
            ['document_id' => DB::table('documents')->where('organization_id', $foreign['organization']->id)->value('id')]] as $invalid) {
            $this->rejected(fn () => DB::table('document_workspace_assignment_changes')->insert(array_replace($audit,
                ['public_id' => (string) Str::ulid(), 'event_key' => (string) Str::ulid()], $invalid)), 1452);
        }
        $document = DB::table('documents')->where('organization_id', $f['organization']->id)->first();
        $this->rejected(fn () => DB::table('document_metadata_revisions')->insert(['public_id' => (string) Str::ulid(),
            'organization_id' => $f['organization']->id, 'document_id' => $document->id, 'actor_id' => $foreign['editor']->id,
            'version_number' => 1, 'title' => 'اختبار', 'description' => 'اختبار', 'created_at' => now('UTC')->format('Y-m-d H:i:s')]), 1452);
        $this->rejected(fn () => DB::table('documents')->where('id', $document->id)->delete(), 1451);
    }

    public function test_each_mysql_atomic_create_failure_is_retryable_and_unknown_existing_objects_are_not_adopted(): void
    {
        $actual = count(glob(database_path('migrations/*.php')));
        foreach (['documents', 'document_workspace_assignments', 'documents'] as $position => $parent) {
            $paths = array_slice(self::PATHS, $position);
            $this->artisan('migrate:rollback', ['--path' => $paths, '--step' => $actual, '--force' => true])->assertExitCode(0);
            $table = ['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'][$position];
            $this->assertFalse(Schema::hasTable($table));
            $renamed = 'document_missing_parent_fixture';
            DB::statement('RENAME TABLE '.$parent.' TO '.$renamed);
            try {
                $migration = require base_path(self::PATHS[$position]);
                try {
                    $migration->up();
                    $this->fail('Missing parent CREATE must fail atomically.');
                } catch (QueryException $error) {
                    $this->assertSame(1824, $error->errorInfo[1]);
                    $this->assertFalse(Schema::hasTable($table));
                    $this->assertSame($actual - count($paths), DB::table('migrations')->count());
                }
            } finally {
                DB::statement('RENAME TABLE '.$renamed.' TO '.$parent);
                $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
            }
        }
        foreach (self::PATHS as $path) {
            $this->rejected(fn () => (require base_path($path))->up(), 1050);
        }
        $this->assertSame($actual, DB::table('migrations')->count());
    }

    public function test_populated_down_of_each_migration_refuses_before_first_drop_and_history_is_immutable(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            app(DocumentWorkspace::class)->updateMetadata($id, ['title' => 'سجل ثابت', 'description' => 'بيانات تاريخية'], 'none');
        });
        $tables = ['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions', 'documents', 'document_versions'];
        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (array_reverse(self::PATHS) as $path) {
            try {
                (require base_path($path))->down();
                $this->fail('Protected populated rollback accepted.');
            } catch (RuntimeException $error) {
                $this->assertStringContainsString('preserved', $error->getMessage());
            }
            foreach ($tables as $table) {
                $this->assertTrue(Schema::hasTable($table));
                $this->assertSame($before[$table], DB::table($table)->orderBy('id')->get()->toJson());
            }
        }
        foreach ([DocumentWorkspaceAssignment::first(), DocumentWorkspaceAssignmentChange::first(), DocumentMetadataRevision::first()] as $model) {
            try {
                $model->delete();
                $this->fail('Historical model deletion accepted.');
            } catch (\LogicException) {
                $this->assertTrue($model->exists);
            }
        }
    }

    public static function initialFaults(): array
    {
        return [['assignment', 1], ['assignment', 3], ['assignment', 6], ['audit', 1], ['audit', 3], ['audit', 6]];
    }

    #[DataProvider('initialFaults')]
    public function test_mysql_initial_assignment_or_audit_failure_preserves_other_documents_and_removes_only_failed_attempt(string $kind, int $position): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, fn () => $this->createWorkspaceDocument($f, 'وثيقة يجب الحفاظ عليها'));
        $files = Storage::disk('local')->allFiles();
        $grants = DB::table('document_workspace_assignments')->count();
        $audit = DB::table('document_workspace_assignment_changes')->count();
        $class = $kind === 'assignment' ? DocumentWorkspaceAssignment::class : DocumentWorkspaceAssignmentChange::class;
        $seen = 0;
        $class::creating(function ($row) use (&$seen, $position): void {
            if ($row->document_id !== null && ++$seen === $position) {
                throw new RuntimeException('Injected actual MySQL document atomic failure.');
            }
        });
        $this->inDocumentFixture($f, function () use ($f): void {
            try {
                $this->createWorkspaceDocument($f, 'المحاولة الفاشلة');
                $this->fail('Partial initial document committed.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected actual MySQL document atomic failure.', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('documents', 1);
        $this->assertDatabaseCount('document_versions', 1);
        $this->assertSame($grants, DB::table('document_workspace_assignments')->count());
        $this->assertSame($audit, DB::table('document_workspace_assignment_changes')->count());
        $this->assertSame($files, Storage::disk('local')->allFiles());
    }

    public function test_final_authorization_after_version_insert_refuses_real_expiry_and_compensates_new_bytes(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $files = Storage::disk('local')->allFiles();
            $expiry = now('UTC')->startOfSecond()->addSeconds(3);
            DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $f['editor']->id)->where('operation', 'append')->update([
                'starts_at' => $expiry->copy()->subDay()->format('Y-m-d H:i:s'), 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
            $inserted = false;
            DocumentVersion::created(function (DocumentVersion $version) use (&$inserted, $expiry): void {
                if ($version->version_number === 2) {
                    $inserted = true;
                    $this->assertTrue(now('UTC')->lessThan($expiry), 'The first authorization really preceded expiry.');
                    usleep(4000000);
                }
            });
            try {
                app(DocumentWorkspace::class)->append($id);
                $this->fail('Expired authority committed after version INSERT.');
            } catch (AuthorizationException) {
                $this->assertTrue($inserted, 'Must exercise the second authorization, not only the precheck.');
                $this->assertDatabaseCount('document_versions', 1);
                $this->assertSame($files, Storage::disk('local')->allFiles());
                $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'expiration_observed')->count());
                $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'access_denied')->count());
            }
        });
    }

    public function test_create_expiry_after_last_initial_audit_rolls_back_every_new_row_and_blob(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $expiry = now('UTC')->startOfSecond()->addSeconds(3);
            DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('operation', 'create')->update([
                'starts_at' => $expiry->copy()->subDay()->format('Y-m-d H:i:s'), 'expires_at' => $expiry->format('Y-m-d H:i:s')]);
            $audits = 0;
            DocumentWorkspaceAssignmentChange::created(function ($change) use (&$audits, $expiry): void {
                if ($change->document_id !== null && ++$audits === 6) {
                    $this->assertTrue(now('UTC')->lessThan($expiry));
                    usleep(4000000);
                }
            });
            try {
                $this->createWorkspaceDocument($f);
                $this->fail('Creation committed after its final audit wait expired the create grant.');
            } catch (AuthorizationException) {
                $this->assertSame(6, $audits);
                $this->assertDatabaseCount('documents', 0);
                $this->assertDatabaseCount('document_versions', 0);
                $this->assertSame(0, DB::table('document_workspace_assignments')->whereNotNull('document_id')->count());
                $this->assertSame(0, DB::table('document_workspace_assignment_changes')->whereNotNull('document_id')->count());
                $this->assertSame([], Storage::disk('local')->allFiles());
                $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'expiration_observed')->count());
            }
        });
    }

    public function test_new_strict_contract_detects_each_fk_unique_check_nullability_and_generated_scope_mutation(): void
    {
        $schema = TenantIsolationSchemaContract::read(DB::connection());
        $this->assertSame([], TenantIsolationSchemaContract::violations($schema));
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            $this->assertSame('direct', TenantIsolationSchemaContract::TABLES[$table]);
            foreach ($schema[$table]['foreign_keys'] as $name => $key) {
                $changed = $schema;
                unset($changed[$table]['foreign_keys'][$name]);
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.'.'.$name);
                $changed = $schema;
                $changed[$table]['foreign_keys'][$name]['delete'] = 'CASCADE';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
            foreach ($schema[$table]['indexes'] as $name => $key) {
                if ($key['unique']) {
                    $changed = $schema;
                    unset($changed[$table]['indexes'][$name]);
                    $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.'.'.$name);
                }
            }
            foreach ($schema[$table]['checks'] as $name => $check) {
                foreach (['enforced' => false, 'clause' => '1'] as $field => $value) {
                    $changed = $schema;
                    $changed[$table]['checks'][$name][$field] = $value;
                    $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.'.'.$name);
                }
            }
            foreach ($schema[$table]['columns'] as $column => $metadata) {
                $changed = $schema;
                $changed[$table]['columns'][$column]['nullable'] = ! $metadata['nullable'];
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed), $table.'.'.$column);
            }
            if (isset($schema[$table]['scope_generation'])) {
                $changed = $schema;
                $changed[$table]['scope_generation']['expression'] = '`document_id`';
                $this->assertNotEmpty(TenantIsolationSchemaContract::violations($changed));
            }
        }
    }
}
