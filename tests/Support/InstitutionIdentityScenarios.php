<?php

namespace Tests\Support;

use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Identity\LoginEmail;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait InstitutionIdentityScenarios
{
    protected function institutionAccount(Organization $organization, string $password = 'first-account-password', string $email = 'employee@example.test'): User
    {
        $user = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $organization->id,
            'email' => $email, 'password' => $password, 'email_verified_at' => null]);
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $organization->id]);

        return $user;
    }

    private function rejectsIdentitySql(callable $operation): void
    {
        try {
            DB::transaction($operation);
            $this->fail('Database must reject an invalid identity relation.');
        } catch (QueryException $error) {
            $this->assertNotEmpty($error->errorInfo);
        }
    }

    public function test_same_normalized_contact_email_has_independent_institution_credentials_and_public_ids(): void
    {
        $a = Organization::factory()->create();
        $b = Organization::factory()->create();
        $first = $this->institutionAccount($a, 'first-account-password', ' Employee@Example.Test ');
        $second = $this->institutionAccount($b, 'second-account-password');
        $this->assertSame($first->email, $second->email);
        $this->assertNotSame($first->id, $second->id);
        $this->assertNotSame($first->public_id, $second->public_id);
        $this->assertTrue(Str::isUlid($first->public_id));
        $this->assertNotSame($first->password, $second->password);
        $this->assertTrue(Hash::check('first-account-password', $first->password));
        $provider = Auth::createUserProvider('institution');
        $this->assertSame($first->id, $provider->retrieveByCredentials(['organization' => $a->public_id, 'email' => 'EMPLOYEE@example.test'])->id);
        $this->assertSame($second->id, $provider->retrieveByCredentials(['organization' => $b->public_id, 'email' => $second->email])->id);
        $this->assertFalse($provider->validateCredentials($second, ['password' => 'first-account-password']));
    }

    public function test_database_rejects_a_duplicate_mailbox_in_one_institution(): void
    {
        $org = Organization::factory()->create();
        $this->institutionAccount($org);
        $this->rejectsIdentitySql(fn () => $this->institutionAccount($org, 'different-password', ' EMPLOYEE@EXAMPLE.TEST '));
        $this->assertSame(1, User::where('principal_type', 'institution')->count());
    }

    public function test_platform_namespace_has_no_null_uniqueness_hole_and_no_membership_or_entry(): void
    {
        $org = Organization::factory()->create();
        $member = $this->institutionAccount($org);
        $platform = User::factory()->create(['principal_type' => 'platform', 'email' => $member->email]);
        $this->assertSame('platform', $platform->fresh()->identity_namespace);
        $this->rejectsIdentitySql(fn () => User::factory()->create(['principal_type' => 'platform', 'email' => strtoupper($member->email)]));
        $this->rejectsIdentitySql(fn () => DB::table('organization_memberships')->insert(['user_id' => $platform->id, 'organization_id' => $org->id]));
        $this->assertNull(Auth::createUserProvider('institution')->retrieveById($platform->id));
        $this->assertNull(Auth::createUserProvider('users')->retrieveById($platform->id));
    }

    public function test_raw_membership_insert_update_and_role_pivot_cannot_add_a_second_institution(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $account = $this->institutionAccount($org);
        $this->rejectsIdentitySql(fn () => DB::table('organization_memberships')->insert(['user_id' => $account->id, 'organization_id' => $other->id]));
        $this->rejectsIdentitySql(fn () => DB::table('organization_memberships')->where('user_id', $account->id)->update(['organization_id' => $other->id]));
        $role = Role::create(['organization_id' => $other->id, 'guard_name' => 'web', 'name' => 'Synthetic identity fixture']);
        $this->rejectsIdentitySql(fn () => DB::table('model_has_roles')->insert(['organization_id' => $other->id,
            'role_id' => $role->id, 'model_id' => $account->id, 'model_type' => User::class]));
        $this->assertSame([$org->id], $account->memberships()->pluck('organization_id')->all());
        $this->assertDatabaseCount('model_has_roles', 0);
    }

    public function test_raw_account_kind_scope_and_public_identifier_are_immutable(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $account = $this->institutionAccount($org);
        foreach (['principal_type' => 'legacy', 'organization_id' => $other->id, 'public_id' => (string) Str::ulid()] as $key => $value) {
            $this->rejectsIdentitySql(fn () => DB::table('users')->where('id', $account->id)->update([$key => $value]));
        }
        $this->assertSame($org->id, $account->fresh()->organization_id);
    }

    public function test_email_normalization_is_explicit_and_unicode_or_control_mailboxes_are_rejected(): void
    {
        $this->assertSame('first.last+tag@example.test', LoginEmail::normalize(' First.Last+tag@Example.Test '));
        foreach (['é@example.test', 'user@مثال.test', "user\n@example.test", 'missing-domain', 'user@example.test x'] as $invalid) {
            $this->assertNull(LoginEmail::normalize($invalid));
        }
        $org = Organization::factory()->create();
        $this->expectException(InvalidArgumentException::class);
        $this->institutionAccount($org, 'valid-password', 'é@example.test');
    }

    public function test_new_identity_shape_and_lowercase_ascii_are_database_constraints(): void
    {
        $org = Organization::factory()->create();
        $base = ['name' => 'Synthetic fixture', 'email' => 'fixture@example.test', 'password' => Hash::make('fixture-password'),
            'principal_type' => 'institution', 'organization_id' => $org->id, 'public_id' => (string) Str::ulid()];
        foreach ([['organization_id' => null], ['public_id' => null], ['principal_type' => 'invented'],
            ['email' => 'UPPER@example.test'], ['email' => 'é@example.test']] as $invalid) {
            $this->rejectsIdentitySql(fn () => DB::table('users')->insert(array_replace($base, $invalid)));
        }
    }

    public function test_legacy_zero_single_and_multiple_memberships_are_preserved_but_quarantined_from_institution_login(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        foreach ([0, 1, 2] as $memberships) {
            $legacy = User::factory()->create();
            foreach (array_slice([$org, $other], 0, $memberships) as $tenant) {
                OrganizationMembership::factory()->create(['user_id' => $legacy->id, 'organization_id' => $tenant->id]);
            }
            $this->assertSame('legacy', $legacy->fresh()->principal_type);
            $this->assertSame($memberships, $legacy->memberships()->count());
            $this->assertNull(Auth::createUserProvider('institution')->retrieveByCredentials(['organization' => $org->public_id, 'email' => $legacy->email]));
            $this->assertSame($legacy->id, Auth::createUserProvider('users')->retrieveById($legacy->id)->id);
        }
    }

    public function test_provider_session_and_remember_resolution_never_crosses_principal_namespaces(): void
    {
        $account = $this->institutionAccount(Organization::factory()->create());
        $legacy = User::factory()->create(['email' => $account->email]);
        $this->assertSame($legacy->id, Auth::createUserProvider('users')->retrieveByCredentials(['email' => $account->email, 'password' => 'ignored-for-lookup'])->id);
        $this->assertNull(Auth::createUserProvider('users')->retrieveById($account->id));
        $this->assertNull(Auth::createUserProvider('users')->retrieveByToken($account->id, $account->remember_token));
        $this->assertNull(Auth::createUserProvider('institution')->retrieveById($legacy->id));
        $this->assertSame($account->id, Auth::createUserProvider('institution')->retrieveByToken($account->id, $account->remember_token)->id);
    }

    public function test_account_reset_reference_is_bound_by_a_real_same_institution_foreign_key(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $account = $this->institutionAccount($org);
        $row = ['account_id' => $account->id, 'organization_id' => $other->id, 'token' => 'synthetic-hash',
            'credential_hash' => str_repeat('a', 64), 'created_at' => now()];
        $this->rejectsIdentitySql(fn () => DB::table('institution_password_reset_tokens')->insert($row));
        $this->assertDatabaseCount('institution_password_reset_tokens', 0);
    }

    public function test_authentication_and_membership_do_not_grant_private_capabilities_or_verify_another_account(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $a = $this->institutionAccount($org);
        $b = $this->institutionAccount($other);
        $a->email_verified_at = now();
        $a->save();
        $this->assertNull($b->fresh()->email_verified_at);
        $this->assertNotSame($a->remember_token, $b->remember_token);
        app(TenantContext::class)->run($a, $org->id, function () use ($a, $org, $other): void {
            $this->assertFalse(app(TenantAuthorization::class)->allows($a, 'not.registered', $org));
            $this->assertFalse(app(TenantAuthorization::class)->allows($a, 'not.registered', $other));
        });
        $this->assertSame([], config('authorization.capabilities'));
        $this->assertDatabaseCount('roles', 0);
        $this->assertDatabaseCount('permissions', 0);
    }
}
