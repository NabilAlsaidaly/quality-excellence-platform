<?php

namespace Tests\Integration\MySql;

use App\Actions\Identity\InstitutionRecovery;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Notifications\InstitutionPasswordReset;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\Support\MySqlTestCase;

class InstitutionIdentityMigrationTest extends MySqlTestCase
{
    use DatabaseMigrations;

    private array $ownedAccounts = [];

    protected function tearDown(): void
    {
        if ($this->app) {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            // Only this test's synthetic principals, before migration teardown.
            DB::table('institution_password_reset_tokens')->whereIn('account_id', $this->ownedAccounts)->delete();
            DB::table('organization_memberships')->whereIn('user_id', $this->ownedAccounts)->delete();
            DB::table('users')->whereIn('id', $this->ownedAccounts)->delete();
        }
        parent::tearDown();
    }

    private function account(Organization $org): User
    {
        $user = User::factory()->create(['principal_type' => 'institution', 'organization_id' => $org->id,
            'email' => 'worker@example.test', 'password' => 'original-account-password']);
        $this->ownedAccounts[] = $user->id;
        OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $org->id]);

        return $user;
    }

    private function expansion()
    {
        return require database_path('migrations/2026_11_05_000001_expand_user_identity_namespaces.php');
    }

    public function test_populated_legacy_expansion_preserves_zero_single_multiple_memberships_passwords_and_history_ids(): void
    {
        $org = Organization::factory()->create();
        $other = Organization::factory()->create();
        $ids = [];
        foreach ([0, 1, 2] as $count) {
            $user = User::factory()->create();
            $ids[] = $user->id;
            foreach (array_slice([$org, $other], 0, $count) as $tenant) {
                OrganizationMembership::factory()->create(['user_id' => $user->id, 'organization_id' => $tenant->id]);
            }
        }
        DB::table('organization_changes')->insert(['organization_id' => $org->id, 'actor_id' => $ids[2], 'event' => 'created', 'new_name' => 'Synthetic preserved history', 'created_at' => now()]);
        $old = DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'password', 'remember_token', 'email_verified_at'])->toArray();
        $history = DB::table('organization_changes')->get()->toArray();
        $memberships = DB::table('organization_memberships')->orderBy('id')->get()->toArray();
        $this->artisan('migrate:rollback', ['--path' => array_map(fn ($path) => 'database/migrations/'.basename($path), glob(database_path('migrations/2026_11_05_*.php'))),
            '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        $this->assertSame(17, DB::table('migrations')->count());
        $this->assertFalse(Schema::hasColumn('users', 'principal_type'));
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $this->assertSame(22, DB::table('migrations')->count());
        $this->assertEquals($old, DB::table('users')->orderBy('id')->get(['id', 'name', 'email', 'password', 'remember_token', 'email_verified_at'])->toArray());
        $this->assertEquals($history, DB::table('organization_changes')->get()->toArray());
        $this->assertEquals($memberships, DB::table('organization_memberships')->orderBy('id')->get()->toArray());
        foreach ($ids as $index => $id) {
            $this->assertSame('legacy', User::findOrFail($id)->principal_type);
            $this->assertNull(User::findOrFail($id)->organization_id);
            $this->assertSame($index, OrganizationMembership::where('user_id', $id)->count());
        }
        try {
            DB::table('users')->where('id', $ids[2])->delete();
            $this->fail('Historical actor must remain restrictive.');
        } catch (QueryException $error) {
            $this->assertSame(1451, $error->errorInfo[1]);
        }
    }

    public function test_missing_fk_target_fails_atomic_alter_without_partial_columns_and_ordinary_migrate_retries(): void
    {
        $this->artisan('migrate:rollback', ['--path' => array_map(fn ($path) => 'database/migrations/'.basename($path), glob(database_path('migrations/2026_11_05_*.php'))),
            '--step' => DB::table('migrations')->count(), '--force' => true])->assertExitCode(0);
        DB::statement('RENAME TABLE organizations TO identity_root_fixture');
        try {
            try {
                $this->expansion()->up();
                $this->fail('Missing root must reject the complete ALTER.');
            } catch (QueryException $error) {
                $this->assertSame(1824, $error->errorInfo[1]);
            }
            $this->assertFalse(Schema::hasColumn('users', 'principal_type'));
            $this->assertFalse(Schema::hasColumn('users', 'organization_id'));
            $this->assertTrue(collect(Schema::getIndexes('users'))->firstWhere('name', 'users_email_unique')['unique']);
            $this->assertSame(17, DB::table('migrations')->count());
        } finally {
            DB::statement('RENAME TABLE identity_root_fixture TO organizations');
            $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        }
        $this->assertSame(22, DB::table('migrations')->count());
    }

    public function test_existing_unjournaled_shape_is_not_adopted_and_semantic_rollback_does_not_erase_accounts_or_tokens(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        // Even with no live tokens, the FIRST reverse migration must refuse
        // before dropping a table or any integrity trigger for existing accounts.
        try {
            Artisan::call('migrate:rollback', ['--path' => array_map(fn ($path) => 'database/migrations/'.basename($path), glob(database_path('migrations/2026_11_05_*.php'))),
                '--step' => DB::table('migrations')->count(), '--force' => true]);
            $this->fail('Ordinary rollback must refuse before its first DROP.');
        } catch (RuntimeException $error) {
            $this->assertNotEmpty($error->getMessage());
        }
        $this->assertSame(22, DB::table('migrations')->count());
        foreach (glob(database_path('migrations/2026_11_05_*.php')) as $path) {
            try {
                (require $path)->down();
                $this->fail('Every reverse step must refuse while principals exist.');
            } catch (RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertTrue(Schema::hasTable('institution_password_reset_tokens'));
        $this->assertSame(3, (int) DB::selectOne("SELECT COUNT(*) AS total FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME IN ('identity_membership_insert','identity_membership_update','identity_binding_update')")->total);
        app(InstitutionRecovery::class)->request($org->public_id, $account->email);
        $before = $account->fresh()->getAttributes();
        foreach ([fn () => $this->expansion()->up(), fn () => $this->expansion()->down(),
            fn () => (require database_path('migrations/2026_11_05_000005_create_institution_password_reset_tokens.php'))->down()] as $operation) {
            try {
                $operation();
                $this->fail('Unsafe adoption/rollback must refuse.');
            } catch (RuntimeException $error) {
                $this->assertNotEmpty($error->getMessage());
            }
        }
        $this->assertSame($before, $account->fresh()->getAttributes());
        $this->assertDatabaseCount('institution_password_reset_tokens', 1);
    }

    public function test_current_membership_lock_denies_recovery_despite_a_revoked_membership_in_an_old_snapshot(): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        app(InstitutionRecovery::class)->request($org->public_id, $account->email);
        $token = Notification::sent($account, InstitutionPasswordReset::class)->sole()->token;
        config(['database.connections.identity_revoker' => config('database.connections.mysql')]);
        $revoker = DB::connection('identity_revoker');
        DB::beginTransaction();
        try {
            $this->assertNotNull(DB::table('organization_memberships')->where('user_id', $account->id)->first());
            $this->assertSame(1, $revoker->table('organization_memberships')->where('user_id', $account->id)->delete());
            $this->assertNotNull(DB::table('organization_memberships')->where('user_id', $account->id)->first());
            $this->assertFalse(app(InstitutionRecovery::class)->reset($org->public_id, $account->public_id, $token, 'unapproved-new-password'));
        } finally {
            DB::rollBack();
            DB::purge('identity_revoker');
        }
        $this->assertTrue(Hash::check('original-account-password', $account->fresh()->password));
    }

    public static function competingOperations(): array
    {
        return ['concurrent token requests retain first token' => ['request'], 'concurrent token consumption rejects replay' => ['reset']];
    }

    #[DataProvider('competingOperations')]
    public function test_real_waiting_connection_rechecks_token_state_after_an_old_snapshot(string $mode): void
    {
        Notification::fake();
        $org = Organization::factory()->create();
        $account = $this->account($org);
        $action = app(InstitutionRecovery::class);
        $token = null;
        if ($mode === 'reset') {
            $action->request($org->public_id, $account->email);
            $token = Notification::sent($account, InstitutionPasswordReset::class)->sole()->token;
        }
        $worker = $this->worker(['mode' => $mode, 'organization' => $org->public_id,
            'account' => $account->public_id, 'email' => $account->email, 'token' => $token]);
        DB::beginTransaction();
        try {
            if ($mode === 'request') {
                $action->request($org->public_id, $account->email);
            } else {
                $this->assertTrue($action->reset($org->public_id, $account->public_id, $token, 'parent-replacement-password'));
            }
            $worker->start();
            $this->assertTrue($worker->waitUntil(fn () => str_contains($worker->getOutput(), 'requesting_lock')), $worker->getErrorOutput());
            $snapshot = json_decode(explode("\n", trim($worker->getOutput()))[0], true, flags: JSON_THROW_ON_ERROR);
            $waiting = false;
            $deadline = microtime(true) + 5;
            do {
                $waiting = (int) DB::selectOne("SELECT COUNT(*) AS total FROM performance_schema.data_lock_waits w JOIN performance_schema.threads t ON t.THREAD_ID = w.REQUESTING_THREAD_ID JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID = w.REQUESTING_ENGINE_LOCK_ID AND l.ENGINE = w.ENGINE WHERE t.PROCESSLIST_ID = ? AND l.OBJECT_SCHEMA = DATABASE() AND l.OBJECT_NAME = 'users'", [$snapshot['connection_id']])->total > 0;
                if (! $waiting) {
                    usleep(10000);
                }
            } while (! $waiting && $worker->isRunning() && microtime(true) < $deadline);
            $this->assertTrue($waiting, 'Second real connection must wait for the account row.');
            DB::commit();
            $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
            $events = array_map(fn ($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), explode("\n", trim($worker->getOutput())));
            $complete = collect($events)->firstWhere('phase', 'complete');
            $this->assertSame(0, $complete['notifications']);
            if ($mode === 'reset') {
                $this->assertFalse($complete['accepted']);
                $this->assertDatabaseCount('institution_password_reset_tokens', 0);
                $this->assertTrue(Hash::check('parent-replacement-password', $account->fresh()->password));
            } else {
                $this->assertDatabaseCount('institution_password_reset_tokens', 1);
                $this->assertSame(1, Notification::sent($account, InstitutionPasswordReset::class)->count());
            }
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($worker->isRunning()) {
                $worker->stop(1);
            }
        }
    }

    private function worker(array $input): Process
    {
        $config = config('database.connections.mysql');
        $worker = new Process([PHP_BINARY, '-d', 'sys_temp_dir='.sys_get_temp_dir(), base_path('tests/Support/identity-worker.php')], base_path(), [
            'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'MYSQL_TESTS_ENABLED' => '1',
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
            'DB_DATABASE' => 'quality_platform_ci', 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
            'DB_URL' => '', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        ]);
        $worker->setInput(json_encode($input, JSON_THROW_ON_ERROR));
        $worker->setTimeout(20);

        return $worker;
    }
}
