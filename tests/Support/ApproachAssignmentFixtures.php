<?php

namespace Tests\Support;

use App\Actions\Approaches\ManageApproachAssignments;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/** Explicit fictional TEST_ONLY fixture; never an operational role definition. */
trait ApproachAssignmentFixtures
{
    use ClosureMigrationFixtures;

    protected array $assignmentFixtures = [];

    protected function assignmentFixture(bool $bootstrap = true): array
    {
        $organization = Organization::factory()->create();
        $unit = OrganizationalUnit::factory()->for($organization)->create(['name' => 'وحدة اختبار المنهجيات']);
        $actors = [];
        foreach (['issuer', 'quality', 'contributor', 'unassigned'] as $name) {
            $actors[$name] = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id]);
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $actors[$name]->id]);
        }
        $db = DB::connection();
        $proof = ['scenario' => 'J03_LOCAL_APPROACH_001', 'fixture_id' => bin2hex(random_bytes(16)),
            'driver' => $db->getDriverName(), 'database' => $db->getDatabaseName(),
            'server_uuid' => $db->getDriverName() === 'mysql' ? $db->selectOne('SELECT @@server_uuid AS uuid')->uuid : null,
            'organization_id' => $organization->id, 'issuer_id' => $actors['issuer']->id,
            'quality_id' => $actors['quality']->id, 'contributor_id' => $actors['contributor']->id];
        $fixture = $actors + compact('organization', 'unit', 'proof');
        $this->assignmentFixtures[] = $fixture;
        if ($bootstrap) {
            $this->inAssignmentFixture($fixture, fn () => app(TenantContext::class)->run($actors['issuer'], $organization->id,
                fn () => app(ManageApproachAssignments::class)->bootstrap($actors['issuer'])));
        }

        return $fixture;
    }

    protected function inAssignmentFixture(array $fixture, Closure $operation): mixed
    {
        return ApproachAssignmentAccess::runFixture(request(), $fixture['proof'], $operation);
    }

    protected function loginAssignmentActor(User $actor, Organization $organization): void
    {
        Auth::guard('institution')->setUser($actor);
        app('session.store')->put(['identity.active_guard' => 'institution',
            'institution.organization' => $organization->public_id, 'institution.password_hash' => $actor->getAuthPassword()]);
        request()->setLaravelSession(app('session.store'));
    }

    protected function assignmentData(array $fixture, string $name = 'منهجية اختبار فعلية'): array
    {
        return ['name' => $name, 'description' => 'وصف اختباري', 'purpose' => 'غاية اختبارية', 'scope' => 'نطاق اختباري',
            'owning_unit_public_id' => $fixture['unit']->public_id];
    }

    protected function createAssignedApproach(array $fixture, string $name = 'منهجية اختبار فعلية'): string
    {
        $this->loginAssignmentActor($fixture['quality'], $fixture['organization']);

        return app(ApproachAccess::class)->create($this->assignmentData($fixture, $name));
    }

    protected function cleanupAssignmentFixtures(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'quality_platform_ci'
            || getenv('MYSQL_TESTS_ENABLED') !== '1') {
            throw new RuntimeException('Refuse fixture cleanup outside owned disposable MySQL.');
        }
        foreach ($this->assignmentFixtures as $f) {
            $organizationId = $f['organization']->id;
            if (Organization::find($organizationId)?->public_id !== $f['organization']->public_id) {
                throw new RuntimeException('Fixture organization ownership changed.');
            }
            foreach (['approach_assignment_changes', 'approach_assignments'] as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->where('organization_id', $organizationId)->where('fixture_id', $f['proof']['fixture_id'])->delete();
                }
            }
            $ids = Approach::where('organization_id', $organizationId)->where('created_by', $f['quality']->id)->pluck('id');
            foreach (['approach_changes', 'approach_content_revisions'] as $table) {
                DB::table($table)->where('organization_id', $organizationId)->whereIn('approach_id', $ids)->delete();
            }
            DB::table('approaches')->where('organization_id', $organizationId)->whereIn('id', $ids)->delete();
            $users = array_map(fn ($key) => $f[$key]->id, ['issuer', 'quality', 'contributor', 'unassigned']);
            DB::table('authorization_changes')->where('organization_id', $organizationId)->whereIn('actor_id', $users)->delete();
            foreach (['model_has_roles', 'model_has_permissions'] as $table) {
                DB::table($table)->where('organization_id', $organizationId)->where('model_type', User::class)->whereIn('model_id', $users)->delete();
            }
            $roles = DB::table('roles')->where('organization_id', $organizationId)->pluck('id');
            DB::table('role_has_permissions')->whereIn('role_id', $roles)->delete();
            DB::table('roles')->where('organization_id', $organizationId)->whereIn('id', $roles)->delete();
            DB::table('organization_memberships')->where('organization_id', $organizationId)->whereIn('user_id', $users)->delete();
            DB::table('users')->where('organization_id', $organizationId)->whereIn('id', $users)->delete();
        }
    }

    /** Remove only EMPTY new FK children before exercising an original migration. */
    protected function rollbackEmptyAssignmentMigrations(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'quality_platform_ci'
            || getenv('MYSQL_TESTS_ENABLED') !== '1') {
            throw new RuntimeException('Refuse original-migration fixture outside disposable MySQL.');
        }
        foreach (['approach_assignments', 'approach_assignment_changes'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated assignment history preserved before any fixture DROP.');
            }
        }
        $this->rollbackEmptyDocumentWorkspaceMigrations();
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_11_10_000001_create_approach_assignments_table.php',
            'database/migrations/2026_11_10_000002_create_approach_assignment_changes_table.php',
        ], '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('approach_assignments'));
        $this->assertFalse(Schema::hasTable('approach_assignment_changes'));
    }

    /** Only for original-migration regression fixtures; never purge populated history. */
    protected function rollbackEmptyDocumentWorkspaceMigrations(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'quality_platform_ci'
            || getenv('MYSQL_TESTS_ENABLED') !== '1' || DB::selectOne('SELECT DATABASE() AS name')->name !== 'quality_platform_ci') {
            throw new RuntimeException('Refuse compatibility cleanup outside disposable MySQL.');
        }
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated document workspace history preserved before any fixture DROP.');
            }
        }
        $this->rollbackEmptyReviewMaturityMigrations();
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_11_11_000001_create_document_workspace_assignments_table.php',
            'database/migrations/2026_11_11_000002_create_document_workspace_assignment_changes_table.php',
            'database/migrations/2026_11_11_000003_create_document_metadata_revisions_table.php',
        ], '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }

    /** Demonstrated original-DDL compatibility only; never discard any workflow history. */
    protected function rollbackEmptyReviewMaturityMigrations(): void
    {
        if (DB::getDriverName() !== 'mysql' || DB::connection()->getDatabaseName() !== 'quality_platform_ci'
            || getenv('MYSQL_TESTS_ENABLED') !== '1' || DB::selectOne('SELECT DATABASE() AS name')->name !== 'quality_platform_ci') {
            throw new RuntimeException('Refuse workflow compatibility rollback outside disposable MySQL.');
        }
        foreach (['maturity_workflow_stages', 'review_maturity_assignments', 'maturity_workflow_configurations', 'maturity_workflow_submissions',
            'maturity_workflow_evidence', 'review_maturity_reviews', 'maturity_workflow_assessments', 'review_maturity_audit', 'review_maturity_notifications'] as $table) {
            if (Schema::hasTable($table) && DB::table($table)->exists()) {
                throw new RuntimeException('Populated review/maturity history preserved before any fixture DROP.');
            }
        }
        $this->rollbackEmptyRealPersistenceMigrations();
        $paths = array_map(fn ($path) => 'database/migrations/'.basename($path), glob(database_path('migrations/2026_11_12_*.php')));
        $this->assertCount(11, $paths, 'Reviewed two candidate keys and nine additive tables.');
        $this->artisan('migrate:rollback', ['--path' => $paths, '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertFalse(Schema::hasTable('review_maturity_reviews'));
        $this->assertFalse(Schema::hasTable('maturity_workflow_stages'));
    }

    /** Exact complete-or-absent fixture preflight; no partial journal adoption or direct DROP. */
    protected function rollbackEmptyRealPersistenceMigrations(): void
    {
        if (! app()->environment('testing') || DB::getDriverName() !== 'mysql'
            || DB::connection()->getDatabaseName() !== 'quality_platform_ci' || getenv('MYSQL_TESTS_ENABLED') !== '1'
            || DB::selectOne('SELECT DATABASE() AS name')->name !== 'quality_platform_ci') {
            throw new RuntimeException('Persistence fixture requires the isolated testing MySQL database.');
        }
        $paths = [
            'database/migrations/2026_11_13_000001_add_reassessment_parent_key.php',
            'database/migrations/2026_11_13_000002_create_document_intakes_table.php',
            'database/migrations/2026_11_13_000003_create_document_intake_events_table.php',
            'database/migrations/2026_11_13_000004_create_maturity_reassessment_attempts_table.php',
            'database/migrations/2026_11_13_000005_create_maturity_reassessment_decisions_table.php',
            'database/migrations/2026_11_13_000006_create_maturity_reassessment_events_table.php',
        ];
        $names = array_map(fn ($path) => basename($path, '.php'), $paths);
        $tables = ['document_intakes', 'document_intake_events', 'maturity_reassessment_attempts',
            'maturity_reassessment_decisions', 'maturity_reassessment_events'];
        $readJournal = fn () => DB::table('migrations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $journal = $readJournal();
        $allNames = array_column($journal, 'migration');
        $known = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/*.php')));
        if (count($allNames) !== count(array_unique($allNames)) || array_diff($allNames, $known)
            || collect($journal)->contains(fn ($row) => $row['id'] < 1 || $row['batch'] < 1)) {
            throw new RuntimeException('Persistence fixture has an unknown or duplicate migration journal.');
        }
        $actualTables = Schema::getTableListing(schemaQualified: false);
        $closureNames = array_map(fn ($path) => basename($path, '.php'), glob(database_path('migrations/2026_11_14_*.php')));
        $closureApplied = array_values(array_intersect($allNames, $closureNames));
        if ($closureApplied !== []) {
            // Validate the ENTIRE older fixture before removing any empty newer dependency.
            // This preserves the original no-DDL guarantee for corrupt/partial journals.
            if ($closureApplied !== $closureNames || array_values(array_diff($known, $allNames)) !== []
                || array_slice(array_values(array_diff($allNames, $closureNames)), -count($names)) !== $names
                || count(array_unique(array_column(array_filter($journal, fn ($row) => in_array($row['migration'], $names, true)), 'batch'))) !== 1
                || TenantIsolationSchemaContract::violations(TenantIsolationSchemaContract::read(DB::connection())) !== []) {
                throw new RuntimeException('Persistence fixture is partial or inconsistent before any reverse DDL.');
            }
            foreach (array_merge($tables, ['document_versions', 'maturity_workflow_assessments', 'maturity_workflow_submissions', 'review_maturity_reviews']) as $table) {
                if (Schema::hasTable($table) && DB::table($table)->exists()) {
                    throw new RuntimeException('Populated persistence history preserved before any fixture DROP.');
                }
            }
            $this->rollbackEmptyClosureFixtures();
            $journal = $readJournal();
            $allNames = array_column($journal, 'migration');
            $known = array_values(array_diff($known, $closureNames));
            $actualTables = Schema::getTableListing(schemaQualified: false);
        } else {
            // Reject unjournaled newer objects too; never adopt a partial fixture.
            $this->rollbackEmptyClosureFixtures();
            $known = array_values(array_diff($known, $closureNames));
        }
        foreach ($actualTables as $table) {
            if ((str_starts_with($table, 'document_intake') || str_starts_with($table, 'maturity_reassessment'))
                && ! in_array($table, $tables, true)) {
                throw new RuntimeException('Persistence fixture has an unknown persistence object.');
            }
        }
        $keyColumns = ['organization_id', 'fixture_id', 'approach_id', 'stage_id', 'id'];
        $readKeys = fn () => Schema::hasTable('maturity_workflow_assessments')
            ? collect(Schema::getIndexes('maturity_workflow_assessments'))->filter(fn ($key) => $key['name'] === 'mw_assess_reassessment_identity' || $key['columns'] === $keyColumns)->values()->all() : [];
        $keys = $readKeys();
        $applied = array_values(array_filter($journal, fn ($row) => in_array($row['migration'], $names, true)));
        $presentTables = array_values(array_filter($tables, Schema::hasTable(...)));
        if ($applied === []) {
            if ($presentTables !== [] || $keys !== []) {
                throw new RuntimeException('Persistence fixture has unjournaled objects; reviewed recovery required.');
            }

            return;
        }
        $sortedJournal = $allNames;
        sort($sortedJournal);
        sort($known);
        if (array_column($applied, 'migration') !== $names || array_slice($allNames, -count($names)) !== $names
            || $sortedJournal !== $known || count(array_unique(array_column($applied, 'batch'))) !== 1
            || count($presentTables) !== count($tables) || count($keys) !== 1
            || $keys[0]['name'] !== 'mw_assess_reassessment_identity' || ! $keys[0]['unique'] || $keys[0]['columns'] !== $keyColumns
            || ! collect(Schema::getIndexes('maturity_workflow_assessments'))->contains(fn ($key) => $key['name'] === 'mw_assess_first_only' && $key['unique']
                && $key['columns'] === ['organization_id', 'fixture_id', 'approach_id', 'stage_id'])) {
            throw new RuntimeException('Persistence fixture is partial or inconsistent before any reverse DDL.');
        }
        // Preflight ALL data, journals, FKs, columns, enforced CHECKs and generated expressions before the first down.
        (require database_path('migrations/2026_11_13_000001_add_reassessment_parent_key.php'))->assertRollbackSafe();
        $expectedJournal = array_values(array_filter($journal, fn ($row) => ! in_array($row['migration'], $names, true)));
        $expectedTables = array_values(array_diff($actualTables, $tables));
        $this->artisan('migrate:rollback', ['--path' => $paths,
            '--step' => count($journal), '--force' => true])->assertExitCode(0);
        $this->assertSame($expectedJournal, $readJournal(), 'Only the six approved journal entries may be removed.');
        $this->assertSame($expectedTables, Schema::getTableListing(schemaQualified: false), 'Only the five approved empty tables may be removed.');
        $this->assertSame([], $readKeys(), 'The approved parent key must be absent after rollback.');
    }
}
