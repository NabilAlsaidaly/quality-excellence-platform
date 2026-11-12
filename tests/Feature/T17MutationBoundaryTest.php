<?php

namespace Tests\Feature;

use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\RoleManagement;
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class T17MutationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $actor = User::factory()->create();
        $organization = Organization::factory()->create();
        OrganizationMembership::factory()->for($actor)->for($organization)->create();

        return [$actor, $organization];
    }

    public function test_callback_receives_fresh_actor_and_actual_locked_scope_without_granting_permission(): void
    {
        [$actor, $organization] = $this->fixture();
        $persisted = $actor->name;
        $actor->name = 'caller altered';
        app(TenantContext::class)->run($actor, $organization->id, function () use ($actor, $organization, $persisted): void {
            app(TenantAuthorization::class)->withinMutation($actor, function (Organization $current, User $fresh) use ($organization, $persisted): void {
                $this->assertSame($organization->id, $current->id);
                $this->assertSame($persisted, $fresh->name);
                $this->assertGreaterThan(0, DB::transactionLevel());
                $this->assertFalse(app(TenantAuthorization::class)->allows($fresh, 'organization.units.manage', $current));
            });
        });
        $this->assertSame([], (require config_path('authorization.php'))['capabilities']);
        $this->assertNull(app(TenantContext::class)->organizationId());
        $this->assertDatabaseCount('authorization_changes', 0);
    }

    public function test_current_decision_keeps_direct_permission_foreign_resource_and_unknown_capability_denied(): void
    {
        [$actor, $organization] = $this->fixture();
        $other = Organization::factory()->create();
        $foreignUnit = OrganizationalUnit::factory()->for($other)->create();
        config(['authorization.capabilities' => ['fixture.current' => Organization::class, 'fixture.unit' => OrganizationalUnit::class]]);
        app(TenantContext::class)->run($actor, $organization->id, function () use ($actor, $organization, $other, $foreignUnit): void {
            $actor->givePermissionTo(Permission::findOrCreate('fixture.current', 'web'));
            app(TenantAuthorization::class)->withinMutation($actor, function () use ($actor, $organization): void {
                $this->assertFalse(app(TenantAuthorization::class)->allows($actor, 'fixture.current', $organization));
            });
            $role = app(RoleManagement::class)->create('current-read fixture');
            app(RoleManagement::class)->syncPermissions($role, ['fixture.current', 'fixture.unit']);
            app(RoleManagement::class)->assign($role, $actor);
            app(TenantAuthorization::class)->withinMutation($actor, function () use ($actor, $organization, $other, $foreignUnit): void {
                $auth = app(TenantAuthorization::class);
                $this->assertTrue($auth->allows($actor, 'fixture.current', $organization));
                $this->assertFalse($auth->allows(null, 'fixture.current', $organization));
                $this->assertFalse($auth->allows($actor, 'unrecognized', $organization));
                $this->assertFalse($auth->allows($actor, 'fixture.current', $other));
                $foreignUnit->organization_id = $organization->id;
                $this->assertFalse($auth->allows($actor, 'fixture.unit', $foreignUnit));
            });
        });
    }

    public function test_old_structural_unit_api_is_preserved_but_its_callback_exception_rolls_back_unit_and_audit(): void
    {
        [$actor, $organization] = $this->fixture();
        try {
            app(TenantContext::class)->run($actor, $organization->id, function () use ($actor): void {
                app(OrganizationalUnitManagement::class)->withinContext($actor, function (int $organizationId, User $fresh): void {
                    $unit = app(OrganizationalUnitManagement::class)->create($fresh, 'internal fixture');
                    app(OrganizationalUnitManagement::class)->rename($fresh, $unit->public_id, 'renamed fixture');
                    $this->assertSame($organizationId, $unit->organization_id);
                    $this->assertDatabaseCount('organizational_unit_changes', 2);
                    throw new RuntimeException('original callback failure');
                });
            });
            $this->fail('Callback failure must escape.');
        } catch (RuntimeException $error) {
            $this->assertSame('original callback failure', $error->getMessage());
        }
        $this->assertDatabaseCount('organizational_units', 0);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_lost_tenant_context_invalidates_the_boundary_and_rolls_back(): void
    {
        [$actor, $organization] = $this->fixture();
        try {
            app(TenantContext::class)->run($actor, $organization->id, function () use ($actor): void {
                app(TenantAuthorization::class)->withinMutation($actor, function () use ($actor): void {
                    app(OrganizationalUnitManagement::class)->create($actor, 'must roll back');
                    app(TenantContext::class)->clear();
                });
            });
            $this->fail('Scope substitution must deny.');
        } catch (AuthorizationException) {
            $this->assertDatabaseCount('organizational_units', 0);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
        }
    }

    public function test_missing_context_and_stale_membership_cannot_enter_callback(): void
    {
        [$actor, $organization] = $this->fixture();
        try {
            app(TenantAuthorization::class)->withinMutation($actor, fn () => $this->fail('No context entered callback.'));
            $this->fail('Missing context must deny.');
        } catch (AuthorizationException) {
            $this->assertNull(app(TenantContext::class)->actorId());
        }
        app(TenantContext::class)->run($actor, $organization->id, function () use ($actor, $organization): void {
            OrganizationMembership::where('organization_id', $organization->id)->where('user_id', $actor->id)->delete();
            $this->expectException(AuthorizationException::class);
            app(TenantAuthorization::class)->withinMutation($actor, fn () => $this->fail('Revoked member entered callback.'));
        });
    }
}
