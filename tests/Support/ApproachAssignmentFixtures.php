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
        $this->artisan('migrate:rollback', ['--path' => [
            'database/migrations/2026_11_11_000001_create_document_workspace_assignments_table.php',
            'database/migrations/2026_11_11_000002_create_document_workspace_assignment_changes_table.php',
            'database/migrations/2026_11_11_000003_create_document_metadata_revisions_table.php',
        ], '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        foreach (['document_workspace_assignments', 'document_workspace_assignment_changes', 'document_metadata_revisions'] as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
    }
}
