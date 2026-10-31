<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationalUnit;
use App\Models\OrganizationMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TenantSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizations_have_unique_public_ulids_and_integer_primary_keys(): void
    {
        $organizations = Organization::factory()->count(3)->create();

        $this->assertCount(3, $organizations->pluck('public_id')->unique());
        foreach ($organizations as $organization) {
            $this->assertTrue(Str::isUlid($organization->public_id));
            $this->assertIsInt($organization->getKey());
            $this->assertTrue($organization->getIncrementing());
            $this->assertSame('public_id', $organization->getRouteKeyName());
            $this->assertTrue($organization->is($organization->resolveRouteBinding($organization->public_id)));
        }
    }

    public function test_global_user_can_have_multiple_memberships_without_changing_authentication(): void
    {
        $user = User::factory()->create();
        $organizations = Organization::factory()->count(2)->create();
        foreach ($organizations as $organization) {
            OrganizationMembership::factory()->for($user)->for($organization)->create();
        }

        $this->assertCount(2, $user->memberships);
        $this->assertEqualsCanonicalizing($organizations->modelKeys(), $user->organizations->modelKeys());
        $membership = $user->memberships->first();
        $this->assertTrue($user->is($membership->user));
        $this->assertTrue($membership->organization->users->contains($user));
        $this->assertTrue($membership->organization->memberships->contains($membership));
        $this->assertTrue(Hash::check('password', $user->getAuthPassword()));
        $this->assertArrayNotHasKey('password', $user->toArray());
        $this->assertArrayNotHasKey('remember_token', $user->toArray());
        $this->assertNull($user->getAttribute('organization_id'));
        $this->assertNull($membership->getAttribute('status'));
    }

    public function test_duplicate_membership_is_rejected(): void
    {
        $membership = OrganizationMembership::factory()->create();
        $this->expectException(QueryException::class);
        OrganizationMembership::factory()->create($membership->only('user_id', 'organization_id'));
    }

    public function test_root_and_child_units_belong_to_the_same_organization(): void
    {
        $root = OrganizationalUnit::factory()->create();
        $child = OrganizationalUnit::factory()->childOf($root)->create();

        $this->assertNull($root->parent);
        $this->assertTrue($root->is($child->parent));
        $this->assertTrue($root->children->contains($child));
        $this->assertTrue($root->organization->units->contains($child));
        $this->assertSame($root->organization_id, $child->organization_id);
        $this->assertTrue(Str::isUlid($child->public_id));
        $this->assertIsInt($child->getKey());
        $this->assertNotSame($root->public_id, $child->public_id);
    }

    public static function publicModels(): array
    {
        return ['organization' => [Organization::class], 'unit' => [OrganizationalUnit::class]];
    }

    #[DataProvider('publicModels')]
    public function test_public_identifier_is_immutable_after_creation(string $modelClass): void
    {
        $model = $modelClass::factory()->create();
        $original = $model->public_id;
        $model->name = 'Renamed fixture';
        $model->save();
        $this->assertSame($original, $model->fresh()->public_id);
        $model->public_id = (string) Str::ulid();

        try {
            $model->save();
            $this->fail('Changing a public identifier must fail.');
        } catch (LogicException $exception) {
            $this->assertSame('A persisted public identifier is immutable.', $exception->getMessage());
        }
        $this->assertSame($original, $model->fresh()->public_id);
    }

    #[DataProvider('publicModels')]
    public function test_public_identifiers_are_unique_in_the_database(string $modelClass): void
    {
        $model = $modelClass::factory()->create();
        $this->expectException(QueryException::class);
        $modelClass::factory()->create(['public_id' => $model->public_id]);
    }

    public function test_unit_tenant_and_identifiers_are_not_mass_assignable(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $original = $unit->only('id', 'public_id', 'organization_id', 'parent_id');
        $unit->fill(['name' => 'Allowed rename', 'id' => 99999, 'public_id' => (string) Str::ulid(), 'organization_id' => 99999, 'parent_id' => 99999]);

        $this->assertSame($original, $unit->only('id', 'public_id', 'organization_id', 'parent_id'));
        $this->assertSame('Allowed rename', $unit->name);
    }

    public function test_membership_foreign_keys_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);
        (new OrganizationMembership)->fill(['user_id' => 1, 'organization_id' => 1]);
    }

    public function test_unit_cannot_be_its_own_parent_through_model_save(): void
    {
        $unit = OrganizationalUnit::factory()->create();
        $unit->parent_id = $unit->id;
        $this->expectException(LogicException::class);
        $unit->save();
    }

    public function test_sqlite_composite_fk_rejects_cross_organization_parent(): void
    {
        $parent = OrganizationalUnit::factory()->create();
        $otherOrganization = Organization::factory()->create();
        $this->expectException(QueryException::class);
        OrganizationalUnit::factory()->create(['organization_id' => $otherOrganization->id, 'parent_id' => $parent->id]);
    }
}
