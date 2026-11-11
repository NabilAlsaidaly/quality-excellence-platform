<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Http\Controllers\Institution\OrganizationalUnitController;
use App\Http\Controllers\Institution\OrganizationOverviewController;
use App\Http\Middleware\InstitutionSession;
use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use App\Support\Institution\OrganizationalUnitTreePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InstitutionOrganizationOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only routes. No application route registration is performed.
        Route::get(
            '/institution/{organization}/overview',
            [OrganizationOverviewController::class, 'index'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.organization.overview');

        Route::get(
            '/institution/{organization}/units',
            [OrganizationalUnitController::class, 'index'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.index');

        Route::get(
            '/institution/{organization}/units/create',
            [OrganizationalUnitController::class, 'create'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.create');

        Route::post(
            '/institution/{organization}/units',
            [OrganizationalUnitController::class, 'store'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.store');

        Route::get(
            '/institution/{organization}/units/{unit}/edit',
            [OrganizationalUnitController::class, 'edit'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.edit');

        Route::patch(
            '/institution/{organization}/units/{unit}',
            [OrganizationalUnitController::class, 'update'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.update');

        Route::post(
            '/institution/{organization}/units/{unit}/move',
            [OrganizationalUnitController::class, 'move'],
        )->middleware(['web', InstitutionSession::class])
            ->name('institution.units.move');

        // Refresh the test-only route name lookup after assigning route names.
        Route::getRoutes()->refreshNameLookups();

        foreach ([
            'institution.organization.overview',
            'institution.units.index',
            'institution.units.create',
            'institution.units.store',
            'institution.units.edit',
            'institution.units.update',
            'institution.units.move',
        ] as $routeName) {
            $this->assertTrue(
                Route::has($routeName),
                "Expected test route [$routeName] to be registered.",
            );
        }
    }

    public function test_presenter_exposes_only_public_organizational_unit_fields(): void
    {
        $rootPublicId = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $childPublicId = '01ARZ3NDEKTSV4RRFFQ69G5FAW';

        $tree = [
            'roots' => [$rootPublicId],
            'nodes' => [
                $rootPublicId => [
                    'id' => 10,
                    'public_id' => $rootPublicId,
                    'name' => 'الجامعة',
                    'parent_public_id' => null,
                    'children' => [$childPublicId],
                ],
                $childPublicId => [
                    'id' => 20,
                    'public_id' => $childPublicId,
                    'name' => 'كلية الهندسة',
                    'parent_public_id' => $rootPublicId,
                    'children' => [],
                ],
            ],
        ];

        $result = (new OrganizationalUnitTreePresenter)->present($tree);

        $this->assertSame([
            'roots' => [$rootPublicId],
            'nodes' => [
                $rootPublicId => [
                    'public_id' => $rootPublicId,
                    'name' => 'الجامعة',
                    'parent_public_id' => null,
                    'children' => [$childPublicId],
                ],
                $childPublicId => [
                    'public_id' => $childPublicId,
                    'name' => 'كلية الهندسة',
                    'parent_public_id' => $rootPublicId,
                    'children' => [],
                ],
            ],
        ], $result);

        $serialized = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('"id":', $serialized);
        $this->assertStringNotContainsString('"parent_id":', $serialized);
    }

    public function test_presenter_handles_an_empty_organizational_unit_tree(): void
    {
        $result = (new OrganizationalUnitTreePresenter)->present([
            'roots' => [],
            'nodes' => [],
        ]);

        $this->assertSame([
            'roots' => [],
            'nodes' => [],
        ], $result);
    }

    public function test_institution_membership_without_capabilities_is_denied_without_logging_out(): void
    {
        config(['authorization.capabilities' => []]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');

        $this->get('/institution/'.$organization->public_id.'/overview')
            ->assertForbidden();

        $this->assertAuthenticatedAs($actor, 'institution');

        $this->get('/institution/'.$organization->public_id.'/units')
            ->assertForbidden();

        $this->assertAuthenticatedAs($actor, 'institution');

        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'قسم غير مصرح',
        ])->assertForbidden();

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');

        $this->assertSame([], config('authorization.capabilities'));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
        $this->assertDatabaseCount('model_has_roles', 0);
        $this->assertDatabaseCount('role_has_permissions', 0);
        $this->assertDatabaseCount('organizational_units', 0);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
        $this->assertDatabaseCount('authorization_changes', 0);
    }

    public function test_explicit_view_capability_allows_institution_metadata_and_tree_reads(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create([
            'name' => 'مؤسسة اختبار القراءة',
        ]);

        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->assertAuthenticatedAs($actor, 'institution');

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.view',
        );

        $capabilities = config('authorization.capabilities');

        $this->assertSame(
            Organization::class,
            $capabilities['organization.units.view'],
        );

        $this->assertSame(
            Organization::class,
            $capabilities['organization.units.manage'],
        );

        $this->get('/institution/'.$organization->public_id.'/overview')
            ->assertOk()
            ->assertSee('مؤسسة اختبار القراءة')
            ->assertSee($organization->public_id);

        $this->get('/institution/'.$organization->public_id.'/units')
            ->assertOk();

        // A view-only capability must not authorize mutations.
        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'وحدة يجب رفض إنشائها',
        ])->assertForbidden();

        $this->assertDatabaseCount('organizational_units', 0);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');

        $this->assertDatabaseCount('roles', 1);
        $this->assertDatabaseCount('permissions', 1);
        $this->assertDatabaseCount('model_has_roles', 1);
        $this->assertDatabaseCount('role_has_permissions', 1);
        $this->assertDatabaseCount('organizational_units', 0);
        $this->assertDatabaseCount('organizational_unit_changes', 0);
    }

    public function test_manage_capability_allows_mutations_without_granting_view_access(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create([
            'name' => 'مؤسسة اختبار الإدارة',
        ]);

        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->assertAuthenticatedAs($actor, 'institution');

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        // Management permission does not imply read permission.
        $this->get('/institution/'.$organization->public_id.'/overview')
            ->assertForbidden();

        $this->get('/institution/'.$organization->public_id.'/units')
            ->assertForbidden();

        $sourceParent = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الأصلية']);

        $targetParent = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة المستقبلة']);

        $child = OrganizationalUnit::factory()
            ->childOf($sourceParent)
            ->create(['name' => 'وحدة فرعية']);

        $indexUrl = route('institution.units.index', [
            'organization' => $organization->public_id,
        ]);

        // Create a root unit through the HTTP endpoint.
        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'وحدة جديدة',
        ])->assertRedirect($indexUrl);

        // Rename an existing unit through the PATCH endpoint.
        $this->patch(
            '/institution/'.$organization->public_id.'/units/'.$child->public_id,
            ['name' => 'وحدة معدّلة'],
        )->assertRedirect($indexUrl);

        // Move the renamed unit beneath another parent.
        $this->post(
            '/institution/'.$organization->public_id.'/units/'.$child->public_id.'/move',
            ['parent_public_id' => $targetParent->public_id],
        )->assertRedirect($indexUrl);

        $this->assertDatabaseHas('organizational_units', [
            'organization_id' => $organization->id,
            'name' => 'وحدة جديدة',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $child->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة معدّلة',
            'parent_id' => $targetParent->id,
        ]);

        $this->assertDatabaseCount('organizational_units', 4);
        $this->assertDatabaseCount('organizational_unit_changes', 3);
        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_foreign_missing_and_non_ulid_unit_identifiers_are_hidden_without_writes(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $foreignOrganization = Organization::factory()->create();

        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $localUnit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'وحدة المؤسسة الحالية']);

        $foreignUnit = OrganizationalUnit::factory()
            ->for($foreignOrganization)
            ->create(['name' => 'وحدة المؤسسة الأجنبية']);

        $missingPublicId = (string) Str::ulid();

        $baseUrl = '/institution/'.$organization->public_id;

        // Missing, malformed, numeric and foreign unit identifiers are hidden.
        foreach ([
            $missingPublicId,
            'not-a-valid-ulid',
            (string) $localUnit->id,
            $foreignUnit->public_id,
        ] as $unitPublicId) {
            $this->patch(
                $baseUrl.'/units/'.$unitPublicId,
                ['name' => 'اسم لا يجب حفظه'],
            )->assertNotFound();

            $this->post(
                $baseUrl.'/units/'.$unitPublicId.'/move',
                ['parent_public_id' => null],
            )->assertNotFound();
        }

        // Existing local units cannot be attached to foreign or missing parents.
        foreach ([
            $missingPublicId,
            $foreignUnit->public_id,
        ] as $parentPublicId) {
            $this->post(
                $baseUrl.'/units/'.$localUnit->public_id.'/move',
                ['parent_public_id' => $parentPublicId],
            )->assertNotFound();

            $this->post($baseUrl.'/units', [
                'name' => 'وحدة لا يجب إنشاؤها',
                'parent_public_id' => $parentPublicId,
            ])->assertNotFound();
        }

        // No rejected request may change either tenant's units or create audit.
        $this->assertDatabaseCount('organizational_units', 2);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $localUnit->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة المؤسسة الحالية',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $foreignUnit->id,
            'organization_id' => $foreignOrganization->id,
            'name' => 'وحدة المؤسسة الأجنبية',
            'parent_id' => null,
        ]);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_http_requests_reject_invalid_names_malformed_parents_and_extra_fields_without_writes(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $unit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الأصلية']);

        $baseUrl = '/institution/'.$organization->public_id;
        $createUrl = $baseUrl.'/units/create';
        $editUrl = $baseUrl.'/units/'.$unit->public_id.'/edit';
        $indexUrl = $baseUrl.'/units';

        // Empty and overlong names must fail validation.
        $this->from($createUrl)->post($baseUrl.'/units', [
            'name' => '   ',
        ])->assertSessionHasErrors('name');

        $this->from($createUrl)->post($baseUrl.'/units', [
            'name' => str_repeat('ج', 256),
        ])->assertSessionHasErrors('name');

        // Store must reject caller-supplied ownership and internal fields.
        $this->from($createUrl)->post($baseUrl.'/units', [
            'name' => 'وحدة غير مسموحة',
            'organization_id' => $organization->id,
        ])->assertSessionHasErrors('organization_id');

        // Rename must not accept parent changes through an extra field.
        $this->from($editUrl)->patch(
            $baseUrl.'/units/'.$unit->public_id,
            [
                'name' => 'اسم لا يجب حفظه',
                'parent_public_id' => null,
            ],
        )->assertSessionHasErrors('parent_public_id');

        // Move must not accept unrelated fields.
        $this->from($indexUrl)->post(
            $baseUrl.'/units/'.$unit->public_id.'/move',
            [
                'parent_public_id' => null,
                'name' => 'حقل غير مسموح',
            ],
        )->assertSessionHasErrors('name');

        // A malformed parent identifier must fail request validation.
        $this->from($indexUrl)->post(
            $baseUrl.'/units/'.$unit->public_id.'/move',
            ['parent_public_id' => 'not-a-valid-ulid'],
        )->assertSessionHasErrors('parent_public_id');

        // Validation failures must not mutate units or produce audit events.
        $this->assertDatabaseCount('organizational_units', 1);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $unit->id,
            'organization_id' => $organization->id,
            'name' => 'الوحدة الأصلية',
            'parent_id' => null,
        ]);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_revoked_manage_permission_denies_all_mutations_without_logging_out(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $role = $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $unit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'وحدة قبل سحب الصلاحية']);

        $targetParent = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة المستقبلة']);

        // Revoke the capability through the existing authorization API.
        app(TenantContext::class)->run(
            $actor,
            $organization->public_id,
            function () use ($role): void {
                app(RoleManagement::class)->syncPermissions($role, []);
            },
        );

        $baseUrl = '/institution/'.$organization->public_id;

        // Creation must be denied after permission revocation.
        $this->post($baseUrl.'/units', [
            'name' => 'وحدة لا يجب إنشاؤها',
        ])->assertForbidden();

        // Rename must be denied after permission revocation.
        $this->patch(
            $baseUrl.'/units/'.$unit->public_id,
            ['name' => 'اسم لا يجب حفظه'],
        )->assertForbidden();

        // Moving must be denied after permission revocation.
        $this->post(
            $baseUrl.'/units/'.$unit->public_id.'/move',
            ['parent_public_id' => $targetParent->public_id],
        )->assertForbidden();

        // All denied requests must leave organizational data and audit unchanged.
        $this->assertDatabaseCount('organizational_units', 2);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $unit->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة قبل سحب الصلاحية',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $targetParent->id,
            'organization_id' => $organization->id,
            'name' => 'الوحدة المستقبلة',
            'parent_id' => null,
        ]);

        // Permission revocation alone must not terminate the valid session.
        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_revoked_role_assignment_denies_all_mutations_without_logging_out(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $role = $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $unit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'وحدة قبل إلغاء الدور']);

        $targetParent = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة المستقبلة']);

        // Revoke the actor's role through the existing authorization API.
        app(TenantContext::class)->run(
            $actor,
            $organization->public_id,
            function () use ($role, $actor): void {
                app(RoleManagement::class)->revoke($role, $actor);
            },
        );

        $baseUrl = '/institution/'.$organization->public_id;

        $this->post($baseUrl.'/units', [
            'name' => 'وحدة لا يجب إنشاؤها',
        ])->assertForbidden();

        $this->patch(
            $baseUrl.'/units/'.$unit->public_id,
            ['name' => 'اسم لا يجب حفظه'],
        )->assertForbidden();

        $this->post(
            $baseUrl.'/units/'.$unit->public_id.'/move',
            ['parent_public_id' => $targetParent->public_id],
        )->assertForbidden();

        $this->assertDatabaseCount('organizational_units', 2);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $unit->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة قبل إلغاء الدور',
            'parent_id' => null,
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $targetParent->id,
            'organization_id' => $organization->id,
            'name' => 'الوحدة المستقبلة',
            'parent_id' => null,
        ]);

        // Removing a role does not itself invalidate the institution session.
        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_revoked_membership_ends_institution_session_before_unit_mutation(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $role = $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $existingUnit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'وحدة موجودة']);

        // Remove the role assignment before revoking its scoped membership.
        app(TenantContext::class)->run(
            $actor,
            $organization->public_id,
            function () use ($role, $actor): void {
                app(RoleManagement::class)->revoke($role, $actor);
            },
        );

        // Revoke the current institution membership.
        $actor->memberships()
            ->where('organization_id', $organization->id)
            ->delete();

        // The institution session must end before the mutation can execute.
        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'وحدة لا يجب إنشاؤها',
        ])->assertRedirect('/institution/login');

        $this->assertGuest('institution');
        $this->assertGuest('web');
        $this->assertNull(app(TenantContext::class)->actorId());
        $this->assertNull(app(TenantContext::class)->organizationId());

        // No organizational mutation or audit may occur after membership revocation.
        $this->assertDatabaseCount('organizational_units', 1);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $existingUnit->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة موجودة',
            'parent_id' => null,
        ]);
    }

    public function test_changed_password_ends_institution_session_before_unit_mutation(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $existingUnit = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'وحدة موجودة']);

        // Simulate a password change from another authenticated session.
        $actor->password = 'changed-elsewhere-password';
        $actor->save();

        // The stale institution session must not authorize a mutation.
        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'وحدة لا يجب إنشاؤها',
        ])->assertRedirect('/institution/login');

        $this->assertGuest('institution');
        $this->assertGuest('web');
        $this->assertNull(app(TenantContext::class)->actorId());
        $this->assertNull(app(TenantContext::class)->organizationId());

        // The password change must not be followed by an organizational write.
        $this->assertDatabaseCount('organizational_units', 1);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $existingUnit->id,
            'organization_id' => $organization->id,
            'name' => 'وحدة موجودة',
            'parent_id' => null,
        ]);
    }

    public function test_http_store_creates_child_using_parent_ulid_and_records_audit(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $parent = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الرئيسية']);

        $indexUrl = route('institution.units.index', [
            'organization' => $organization->public_id,
        ]);

        // The HTTP request uses the parent's public ULID, never its internal ID.
        $this->post('/institution/'.$organization->public_id.'/units', [
            'name' => 'الوحدة الفرعية الجديدة',
            'parent_public_id' => $parent->public_id,
        ])->assertRedirect($indexUrl);

        $child = OrganizationalUnit::query()
            ->where('organization_id', $organization->id)
            ->where('name', 'الوحدة الفرعية الجديدة')
            ->sole();

        $this->assertSame($organization->id, $child->organization_id);
        $this->assertSame($parent->id, $child->parent_id);
        $this->assertSame($parent->public_id, $child->fresh()->parent->public_id);

        $this->assertDatabaseCount('organizational_units', 2);
        $this->assertDatabaseCount('organizational_unit_changes', 1);

        $this->assertDatabaseHas('organizational_unit_changes', [
            'organization_id' => $organization->id,
            'actor_id' => $actor->id,
            'unit_id' => $child->id,
            'event' => 'created',
            'previous_parent_id' => null,
            'new_parent_id' => $parent->id,
            'previous_name' => null,
            'new_name' => 'الوحدة الفرعية الجديدة',
        ]);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_http_move_rejects_self_and_descendant_cycles_without_changes(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.manage',
        );

        $root = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الجذرية']);

        $child = OrganizationalUnit::factory()
            ->childOf($root)
            ->create(['name' => 'الوحدة الفرعية']);

        $grandchild = OrganizationalUnit::factory()
            ->childOf($child)
            ->create(['name' => 'الوحدة الحفيدة']);

        $baseUrl = '/institution/'.$organization->public_id;
        $editUrl = $baseUrl.'/units/'.$root->public_id.'/edit';

        // A unit cannot become its own parent.
        $this->from($editUrl)->post(
            $baseUrl.'/units/'.$child->public_id.'/move',
            ['parent_public_id' => $child->public_id],
        )->assertRedirect($editUrl)
            ->assertSessionHasErrors('parent_public_id');

        $this->assertSame(
            __('institution_organization.validation.invalid_hierarchy'),
            session('errors')->first('parent_public_id'),
        );

        // A root cannot be moved beneath one of its descendants.
        $this->from($editUrl)->post(
            $baseUrl.'/units/'.$root->public_id.'/move',
            ['parent_public_id' => $grandchild->public_id],
        )->assertRedirect($editUrl)
            ->assertSessionHasErrors('parent_public_id');

        $this->assertSame(
            __('institution_organization.validation.invalid_hierarchy'),
            session('errors')->first('parent_public_id'),
        );

        // Both rejected moves must preserve the original hierarchy.
        $this->assertDatabaseCount('organizational_units', 3);
        $this->assertDatabaseCount('organizational_unit_changes', 0);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $root->id,
            'organization_id' => $organization->id,
            'parent_id' => null,
            'name' => 'الوحدة الجذرية',
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $child->id,
            'organization_id' => $organization->id,
            'parent_id' => $root->id,
            'name' => 'الوحدة الفرعية',
        ]);

        $this->assertDatabaseHas('organizational_units', [
            'id' => $grandchild->id,
            'organization_id' => $organization->id,
            'parent_id' => $child->id,
            'name' => 'الوحدة الحفيدة',
        ]);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_create_page_renders_with_public_parent_identifiers_for_view_capability(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.view',
        );

        $root = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الجذرية']);

        $baseUrl = '/institution/'.$organization->public_id;
        $createAction = route('institution.units.store', [
            'organization' => $organization->public_id,
        ]);

        $response = $this->get($baseUrl.'/units/create')
            ->assertOk();

        $response->assertSee(__('institution_organization.units.create_title'));
        $response->assertSee('action="'.$createAction.'"', false);
        $response->assertSee('name="parent_public_id"', false);
        $response->assertSee('value="'.$root->public_id.'"', false);
        $response->assertDontSee('value="'.$root->id.'"', false);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    public function test_edit_page_renders_rename_and_move_forms_with_public_identifiers_for_view_capability(): void
    {
        config(['authorization.capabilities' => [
            'organization.units.view' => Organization::class,
            'organization.units.manage' => Organization::class,
        ]]);

        $organization = Organization::factory()->create();
        $actor = $this->createInstitutionAccount($organization);

        $this->post('/institution/login', [
            'organization' => $organization->public_id,
            'email' => $actor->email,
            'password' => 'first-account-password',
        ])->assertRedirect(route('institution.account', [
            'organization' => $organization->public_id,
        ]));

        $this->grantCapability(
            $actor,
            $organization,
            'organization.units.view',
        );

        $root = OrganizationalUnit::factory()
            ->for($organization)
            ->create(['name' => 'الوحدة الجذرية']);

        $child = OrganizationalUnit::factory()
            ->childOf($root)
            ->create(['name' => 'الوحدة الفرعية']);

        $baseUrl = '/institution/'.$organization->public_id;
        $editUrl = $baseUrl.'/units/'.$child->public_id.'/edit';

        $updateAction = route('institution.units.update', [
            'organization' => $organization->public_id,
            'unit' => $child->public_id,
        ]);

        $moveAction = route('institution.units.move', [
            'organization' => $organization->public_id,
            'unit' => $child->public_id,
        ]);

        $response = $this->get($editUrl)
            ->assertOk();

        $response->assertSee(__('institution_organization.units.edit_title'));
        $response->assertSee('الوحدة الفرعية');
        $response->assertSee('الوحدة الجذرية');
        $response->assertSee('action="'.$updateAction.'"', false);
        $response->assertSee('action="'.$moveAction.'"', false);
        $response->assertSee('name="_method" value="PATCH"', false);
        $response->assertSee('value="'.$child->public_id.'"', false);
        $response->assertSee('value="'.$root->public_id.'"', false);
        $response->assertDontSee('value="'.$child->id.'"', false);
        $response->assertDontSee('value="'.$root->id.'"', false);

        $this->assertAuthenticatedAs($actor, 'institution');
        $this->assertGuest('web');
    }

    private function createInstitutionAccount(Organization $organization): User
    {
        $actor = User::factory()->create([
            'principal_type' => 'institution',
            'organization_id' => $organization->id,
            'email' => 'employee@example.test',
            'password' => 'first-account-password',
            'email_verified_at' => null,
        ]);

        OrganizationMembership::factory()->create([
            'user_id' => $actor->id,
            'organization_id' => $organization->id,
        ]);

        return $actor;
    }

    private function grantCapability(
        User $actor,
        Organization $organization,
        string $capability,
    ): Role {
        return app(TenantContext::class)->run(
            $actor,
            $organization->public_id,
            function () use ($actor, $capability): Role {
                $manager = app(RoleManagement::class);
                $role = $manager->create('Issue 37 synthetic fixture');

                $manager->syncPermissions($role, [$capability]);
                $manager->assign($role, $actor);

                return $role;
            },
        );
    }
}
