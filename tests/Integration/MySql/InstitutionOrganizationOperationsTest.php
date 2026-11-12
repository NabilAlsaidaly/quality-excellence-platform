<?php

namespace Tests\Integration\MySql;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\Support\MySqlTestCase;

final class InstitutionOrganizationOperationsTest extends MySqlTestCase
{
    use DatabaseMigrations;

    private array $ownedAccounts = [];

    protected function tearDown(): void
    {
        try {
            if ($this->app) {
                while (DB::transactionLevel() > 0) {
                    DB::rollBack();
                }
                // Delete only identities and related fixture rows created by this test.
                // The original migration guards still run during framework teardown.
                $ids = array_keys($this->ownedAccounts);
                foreach ($this->ownedAccounts as $id => $organizationId) {
                    if (User::find($id)?->organization_id !== $organizationId) {
                        throw new \RuntimeException('Owned unit fixture identity changed.');
                    }
                }
                $organizations = array_unique(array_values($this->ownedAccounts));
                DB::table('organizational_unit_changes')->whereIn('organization_id', $organizations)->whereIn('actor_id', $ids)->delete();
                DB::table('authorization_changes')->whereIn('organization_id', $organizations)->whereIn('actor_id', $ids)->delete();
                DB::table('model_has_roles')->where('model_type', User::class)->whereIn('organization_id', $organizations)->whereIn('model_id', $ids)->delete();
                DB::table('institution_password_reset_tokens')->whereIn('account_id', $ids)->delete();
                DB::table('organization_memberships')->whereIn('organization_id', $organizations)->whereIn('user_id', $ids)->delete();
                DB::table('users')->whereIn('id', $ids)->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * The original characterization at contributor SHA 5c86b4f demonstrated
     * an unsafe successful write. Integrated acceptance preserves that exact
     * two-connection schedule and now requires denial without domain/audit data.
     */
    public function test_t17_rejects_permission_revocation_between_gate_and_unit_write(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionActor($organization, 't17-actor@example.test');
        $revoker = $this->createInstitutionActor($organization, 't17-revoker@example.test');

        $context = app(TenantContext::class);

        $role = $context->run(
            $revoker,
            $organization->public_id,
            function () use ($actor): Role {
                $management = app(RoleManagement::class);
                $role = $management->create('Issue 37 T17 fixture');

                $management->syncPermissions($role, ['organization.units.manage']);
                $management->assign($role, $actor);

                return $role;
            },
        );

        $this->post('/institution/login', ['organization' => $organization->public_id,
            'email' => $actor->email, 'password' => 'first-account-password'])->assertRedirect();
        $this->assertAuthenticatedAs($actor, 'institution');

        $gateInitiallyAllowed = $context->run(
            $actor,
            $organization->public_id,
            fn (): bool => Gate::forUser($actor)->allows(
                'tenant-capability',
                ['organization.units.manage', $organization],
            ),
        );

        $this->assertTrue($gateInitiallyAllowed);

        $primary = DB::connection('mysql');

        config([
            'database.connections.issue37_t17_revoker' => config('database.connections.mysql'),
        ]);

        DB::purge('issue37_t17_revoker');

        $revokerConnection = DB::connection('issue37_t17_revoker');

        try {
            $primaryInfo = $primary->selectOne(
                'SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id, @@transaction_isolation AS isolation',
            );

            $revokerInfo = $revokerConnection->selectOne(
                'SELECT DATABASE() AS database_name, CONNECTION_ID() AS connection_id, @@transaction_isolation AS isolation',
            );

            $this->assertSame('quality_platform_ci', $primaryInfo->database_name);
            $this->assertSame('quality_platform_ci', $revokerInfo->database_name);
            $this->assertNotSame($primaryInfo->connection_id, $revokerInfo->connection_id);
            $this->assertSame('REPEATABLE-READ', $primaryInfo->isolation);
            $this->assertSame('REPEATABLE-READ', $revokerInfo->isolation);

            $revocationObserved = false;

            $primary->beforeExecuting(
                function (string $sql) use (
                    &$revocationObserved,
                    $primary,
                    $revokerConnection,
                    $context,
                    $revoker,
                    $organization,
                    $role,
                ): void {
                    $normalizedSql = strtolower($sql);

                    if (
                        $revocationObserved
                        || ! str_contains($normalizedSql, 'from `organizations`')
                        || ! str_contains($normalizedSql, 'for update')
                    ) {
                        return;
                    }

                    // The unit-write transaction has started, but its first
                    // organization lock has not yet been acquired.
                    if ($primary->transactionLevel() !== 1) {
                        throw new \LogicException(
                            'The revocation window was not reached inside the unit transaction.',
                        );
                    }

                    // Revoke the capability and commit it through the second
                    // independent MySQL connection.
                    DB::usingConnection(
                        'issue37_t17_revoker',
                        fn () => $context->run(
                            $revoker,
                            $organization->public_id,
                            fn () => app(RoleManagement::class)->syncPermissions($role, []),
                        ),
                    );

                    if ($revokerConnection->table('role_has_permissions')
                        ->where('role_id', $role->getKey())->exists()) {
                        throw new \RuntimeException('The second connection did not commit permission revocation.');
                    }

                    $revocationObserved = true;
                },
            );

            // The adapter performs its own Gate check. The callback above
            // revokes the permission after that check and before the unit
            // service acquires its first organization lock.
            $denied = false;
            try {
                $context->run($actor, $organization->public_id,
                    fn () => app(AuthorizedUnitOperations::class)->create($actor, $organization->public_id, 'Created after permission revocation'));
            } catch (AuthorizationException) {
                $denied = true;
            } catch (HttpExceptionInterface $error) {
                $this->assertSame(403, $error->getStatusCode());
                $denied = true;
            }
            $this->assertTrue($denied, 'The actual adapter must refuse the committed permission revocation.');

            $this->assertTrue(
                $revocationObserved,
                'The test must revoke permission in the intended Gate-to-write window.',
            );

            $gateAfterRevocation = $context->run(
                $actor,
                $organization->public_id,
                fn (): bool => Gate::forUser($actor)->allows(
                    'tenant-capability',
                    ['organization.units.manage', $organization],
                ),
            );

            $this->assertFalse($gateAfterRevocation);
            $this->assertDatabaseMissing('role_has_permissions', [
                'role_id' => $role->getKey(),
            ]);

            // Same original schedule, strengthened from known unsafe behavior
            // into the required fail-closed regression. Historical RED retained.
            $this->assertDatabaseCount('organizational_units', 0);
            $this->assertDatabaseCount('organizational_unit_changes', 0);
            $this->assertDatabaseMissing('organizational_units', [
                'organization_id' => $organization->id,
                'name' => 'Created after permission revocation',
                'parent_id' => null,
            ]);
        } finally {
            DB::purge('issue37_t17_revoker');
        }
    }

    private function createInstitutionActor(Organization $organization, string $email): User
    {
        $actor = User::factory()->create([
            'principal_type' => 'institution',
            'organization_id' => $organization->id,
            'email' => $email,
            'password' => 'first-account-password',
            'email_verified_at' => null,
        ]);

        OrganizationMembership::factory()->create([
            'user_id' => $actor->id,
            'organization_id' => $organization->id,
        ]);

        $this->ownedAccounts[$actor->id] = (int) $organization->id;

        return $actor;
    }
}
