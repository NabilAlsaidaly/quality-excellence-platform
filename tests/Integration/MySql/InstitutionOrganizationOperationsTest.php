<?php

namespace Tests\Integration\MySql;

use App\Actions\OrganizationalUnits\AuthorizedUnitOperations;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Support\MySqlTestCase;

final class InstitutionOrganizationOperationsTest extends MySqlTestCase
{
    use DatabaseMigrations;

    /**
     * Characterization test for the current Gate-to-write gap.
     *
     * A passing test demonstrates that a permission revoked after Gate
     * authorization can still be followed by a successful unit write.
     * This does not satisfy T17. The shared locking/authorization protocol
     * remains a separate integration decision under Issue #58.
     */
    public function test_t17_characterizes_permission_revocation_between_gate_and_unit_write(): void
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

        Auth::guard('institution')->setUser($actor);

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
            $context->run(
                $actor,
                $organization->public_id,
                fn () => app(AuthorizedUnitOperations::class)->create(
                    $actor,
                    $organization->public_id,
                    'Created after permission revocation',
                ),
            );

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

            // Characterization result: current code still writes after the
            // capability has been revoked on the independent connection.
            $this->assertDatabaseCount('organizational_units', 1);
            $this->assertDatabaseCount('organizational_unit_changes', 1);
            $this->assertDatabaseHas('organizational_units', [
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

        return $actor;
    }
}
