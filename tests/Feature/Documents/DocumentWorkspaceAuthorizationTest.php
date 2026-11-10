<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\DocumentWorkspace;
use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Models\Document;
use App\Models\DocumentWorkspaceAssignment;
use App\Models\DocumentWorkspaceAssignmentChange;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\Support\DocumentWorkspaceFixtures;
use Tests\TestCase;

class DocumentWorkspaceAuthorizationTest extends TestCase
{
    use DatabaseMigrations, DocumentWorkspaceFixtures;

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

    public function test_additive_tables_keep_explicit_tenant_user_key_order_and_description_capacity(): void
    {
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table.' is required.');
        }
        foreach (['document_workspace_assignments' => ['grantee_id', 'issuer_id', 'revoked_by'],
            'document_workspace_assignment_changes' => ['actor_id', 'subject_id'],
            'document_metadata_revisions' => ['actor_id']] as $table => $fields) {
            $fks = collect(DB::select('PRAGMA foreign_key_list('.$table.')'))->groupBy('id');
            foreach ($fields as $field) {
                $fk = $fks->first(fn ($rows) => $rows->first()->table === 'users' && $rows->first()->from === $field);
                $this->assertNotNull($fk, $table.'.'.$field);
                $this->assertSame([$field, 'organization_id'], $fk->sortBy('seq')->pluck('from')->all());
                $this->assertSame(['id', 'organization_id'], $fk->sortBy('seq')->pluck('to')->all());
            }
        }
        $description = collect(DB::select('PRAGMA table_info(document_metadata_revisions)'))->firstWhere('name', 'description');
        $this->assertSame('LONGTEXT', strtoupper($description->type));
    }

    public function test_actual_assignment_policy_creation_metadata_append_and_safe_history_without_approach_permissions(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $workspace = app(DocumentWorkspace::class);
            $original = $workspace->show($id);
            $this->assertSame('none', $original['metadata_token']);
            $this->assertSame(6, DB::table('document_workspace_assignments')->whereNotNull('document_id')->count());
            $this->assertSame(6, DB::table('document_workspace_assignment_changes')->whereNotNull('document_id')->where('event', 'granted')->count());
            $this->assertDatabaseCount('approach_assignments', 0);
            $this->assertFalse(DB::table('permissions')->where('name', 'approaches.access')->exists());
            $bytes = DB::table('document_versions')->first();
            $workspace->updateMetadata($id, ['title' => 'عنوان محدث', 'description' => str_repeat('ع', 40000)], 'none');
            $current = $workspace->show($id);
            $this->assertSame('عنوان محدث', $current['title']);
            $this->assertNotSame('none', $current['metadata_token']);
            $this->assertSame('وثيقة اختبار مؤسسية', Document::first()->title);
            $workspace->append($id);
            $this->assertDatabaseCount('document_versions', 2);
            $this->assertSame($bytes->sha256, DB::table('document_versions')->where('id', $bytes->id)->value('sha256'));
            foreach (['versions', 'metadata'] as $type) {
                $history = $workspace->history($id, $type);
                $this->assertGreaterThan(0, $history->total());
                $this->assertStringNotContainsString('storage_key', json_encode($history->items()));
                $this->assertStringNotContainsString('original_filename', json_encode($history->items()));
                $this->assertStringNotContainsString('sha256', json_encode($history->items()));
            }
        });
    }

    public function test_reader_listing_filters_assignment_before_search_count_and_pagination_and_denies_writes(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f, 'دليل ظاهر');
            $hidden = $this->unassignedWorkspaceDocument($f);
            $this->loginDocumentActor($f['reader'], $f['organization']);
            $workspace = app(DocumentWorkspace::class);
            $listing = $workspace->listing(perPage: 1);
            $this->assertSame(1, $listing->total());
            $this->assertSame($id, $listing->first()['document_public_id']);
            $this->assertSame(0, $workspace->listing('غير مسندة')->total());
            $this->assertSame(0, $workspace->listing('%')->total());
            foreach ([fn () => $workspace->append($id), fn () => $workspace->updateMetadata($id, ['title' => 'مرفوض', 'description' => 'مرفوض'], 'none'),
                fn () => $workspace->show($hidden)] as $attempt) {
                try {
                    $attempt();
                    $this->fail('Unassigned write/read accepted.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('document_versions', 2);
                }
            }
            $this->assertSame(3, DB::table('document_workspace_assignment_changes')->where('event', 'access_denied')->count());
        });
    }

    public function test_metadata_noop_stale_conflict_and_session_password_invalidation_preserve_history(): void
    {
        $f = $this->documentFixture();
        $this->inDocumentFixture($f, function () use ($f): void {
            $id = $this->createWorkspaceDocument($f);
            $workspace = app(DocumentWorkspace::class);
            $data = ['title' => 'وثيقة اختبار مؤسسية', 'description' => 'وصف عربي محفوظ للوثيقة'];
            $workspace->updateMetadata($id, $data, 'none');
            $this->assertDatabaseCount('document_metadata_revisions', 0);
            $workspace->updateMetadata($id, ['title' => 'نسخة بيانات جديدة', 'description' => 'وصف آخر'], 'none');
            try {
                $workspace->updateMetadata($id, $data, 'none');
                $this->fail('Stale metadata overwrite accepted.');
            } catch (ConflictHttpException) {
                $this->assertDatabaseCount('document_metadata_revisions', 1);
            }
            User::findOrFail($f['editor']->id)->forceFill(['password' => 'changed-fixture-password'])->save();
            $this->assertFalse($workspace->can('list'));
            $this->assertDatabaseCount('document_metadata_revisions', 1);
        });
    }

    public function test_revoke_and_exact_expiry_fail_closed_with_once_only_observation_and_no_outside_fixture_access(): void
    {
        $f = $this->documentFixture();
        $id = $this->inDocumentFixture($f, function () use ($f): string {
            $id = $this->createWorkspaceDocument($f);
            $assignment = DB::table('document_workspace_assignments')->where('grantee_id', $f['reader']->id)->where('operation', 'view')->first();
            $this->loginDocumentActor($f['issuer'], $f['organization']);
            app(ManageDocumentWorkspaceAssignments::class)->revoke($f['issuer'], $assignment->public_id);
            $this->loginDocumentActor($f['reader'], $f['organization']);
            $this->assertSame(0, app(DocumentWorkspace::class)->listing()->total());
            $expires = DB::table('document_workspace_assignments')->where('grantee_id', $f['editor']->id)->where('operation', 'create')->value('expires_at');
            $this->travelTo(CarbonImmutable::parse($expires, 'UTC'));
            $this->loginDocumentActor($f['editor'], $f['organization']);
            foreach ([1, 2] as $attempt) {
                try {
                    app(DocumentWorkspace::class)->create(['title' => 'منتهي', 'description' => 'منتهي']);
                    $this->fail('Expired grant accepted.');
                } catch (AuthorizationException) {
                    $this->assertDatabaseCount('documents', 1);
                }
            }
            $this->assertSame(1, DB::table('document_workspace_assignment_changes')->where('event', 'expiration_observed')->count());
            $this->travelBack();

            return $id;
        });
        $this->assertFalse(app(DocumentWorkspace::class)->can('view', $id));
    }

    public static function creationFaults(): array
    {
        return [['assignment', 1], ['assignment', 3], ['assignment', 6], ['audit', 1], ['audit', 3], ['audit', 6]];
    }

    public function test_sqlite_raw_expiry_and_unsigned_metadata_range_match_the_mysql_contract(): void
    {
        $f = $this->documentFixture();
        $id = $this->inDocumentFixture($f, fn () => $this->createWorkspaceDocument($f));
        $assignment = (array) DB::table('document_workspace_assignments')->first();
        unset($assignment['id'], $assignment['scope_key']);
        foreach ([['2026-11-10 00:00:00', '2026-11-10 00:00:00'], ['2026-11-10 00:00:00', '2026-11-10 23:59:59'],
            ['9999-12-31 00:00:00', '9999-12-31 23:59:59'], ['not-a-date', '2026-11-11 00:00:00']] as [$start, $end]) {
            DB::beginTransaction();
            try {
                DB::table('document_workspace_assignments')->insert(array_replace($assignment, ['public_id' => (string) Str::ulid(),
                    'fixture_id' => bin2hex(random_bytes(16)), 'starts_at' => $start, 'expires_at' => $end]));
                $this->fail('SQLite accepted invalid assignment time.');
            } catch (QueryException) {
                $this->assertDatabaseCount('document_workspace_assignments', 9);
            } finally {
                DB::rollBack();
            }
        }
        foreach ([0, 4294967296] as $version) {
            DB::beginTransaction();
            try {
                DB::table('document_metadata_revisions')->insert(['public_id' => (string) Str::ulid(),
                    'organization_id' => $f['organization']->id, 'document_id' => Document::where('public_id', $id)->value('id'),
                    'actor_id' => $f['editor']->id, 'version_number' => $version, 'title' => 'اختبار', 'description' => 'اختبار', 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
                $this->fail('SQLite accepted an out-of-range unsigned metadata revision.');
            } catch (QueryException) {
                $this->assertDatabaseCount('document_metadata_revisions', 0);
            } finally {
                DB::rollBack();
            }
        }
    }

    #[DataProvider('creationFaults')]
    public function test_initial_grant_or_audit_failure_rolls_back_document_version_all_grants_and_owned_bytes(string $kind, int $position): void
    {
        $f = $this->documentFixture();
        $model = $kind === 'assignment' ? DocumentWorkspaceAssignment::class : DocumentWorkspaceAssignmentChange::class;
        $seen = 0;
        $model::creating(function ($row) use (&$seen, $position): void {
            if ($row->document_id !== null && ++$seen === $position) {
                throw new RuntimeException('Injected initial document transaction failure.');
            }
        });
        $this->inDocumentFixture($f, function () use ($f): void {
            try {
                $this->createWorkspaceDocument($f);
                $this->fail('Partially authorized document committed.');
            } catch (RuntimeException $error) {
                $this->assertSame('Injected initial document transaction failure.', $error->getMessage());
            }
        });
        $this->assertDatabaseCount('documents', 0);
        $this->assertDatabaseCount('document_versions', 0);
        $this->assertSame(0, DB::table('document_workspace_assignments')->whereNotNull('document_id')->count());
        $this->assertSame(0, DB::table('document_workspace_assignment_changes')->whereNotNull('document_id')->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
