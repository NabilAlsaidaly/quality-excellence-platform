<?php

// Explicit CLI-only investigation worker, never registered in the application.
use App\Actions\Identity\InstitutionRecovery;
use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'T17 worker failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
});
$data = json_decode(getenv('T17_WORKER_DATA'), true, flags: JSON_THROW_ON_ERROR);
$proof = $data['proof'];
$db = DB::connection();
$identity = $db->selectOne('SELECT DATABASE() AS db, @@server_uuid AS uuid, VERSION() AS version, CONNECTION_ID() AS connection_id');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || getenv('T17_TESTS_ENABLED') !== '1'
    || $identity->db !== 'quality_platform_ci' || $identity->uuid !== $proof['server_uuid']
    || $identity->uuid !== getenv('T17_SERVER_UUID') || ! str_starts_with($identity->version, '8.4.')
    || config('database.connections.mysql.url')) {
    throw new RuntimeException('Refuse unverified T17 worker target.');
}
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET SESSION innodb_lock_wait_timeout = 5');
config(['authorization.capabilities' => ['approaches.access' => Organization::class,
    'organization.units.manage' => Organization::class]]);
$actor = User::findOrFail($proof['issuer_id']);
$subject = User::findOrFail($proof['contributor_id']);
$role = Role::where('organization_id', $proof['organization_id'])->whereKey($data['role'])->firstOrFail();
$mode = $data['mode'];
$release = function (string $expected) use ($data): void {
    $file = $data['barrier'] ?? '';
    if (! is_string($file) || ! is_file($file) || ! str_starts_with(realpath($file), realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)
        || ! str_starts_with(basename($file), 't17')) {
        throw new RuntimeException('Invalid owned barrier.');
    }
    $deadline = microtime(true) + 8;
    while (microtime(true) < $deadline) {
        if (file_get_contents($file) === $expected) {
            return;
        }
        usleep(10000);
    }
    throw new RuntimeException('Controlled barrier not released.');
};
if ($mode === 'deadlock') {
    $roleLocked = false;
    DB::listen(function ($query) use (&$roleLocked): void {
        if (str_contains($query->sql, 'from `roles`') && str_contains($query->sql, 'for update')) {
            $roleLocked = true;
        }
    });
    $db->beforeExecuting(function ($sql) use (&$roleLocked, $release): void {
        if ($roleLocked && str_contains($sql, 'from `organization_memberships`') && str_contains($sql, 'for update')) {
            $roleLocked = false;
            echo "ROLE_LOCKED\n";
            fflush(STDOUT);
            $release('GO');
        }
    });
}
$status = 'committed';
$error = null;
try {
    app(TenantContext::class)->run($actor, $proof['organization_id'], function () use ($mode, $role, $subject, $data, $db, $proof, $release): void {
        if ($mode === 'password-hold') {
            $db->beginTransaction();
            $db->table('organizations')->where('id', $proof['organization_id'])->lockForUpdate()->first();
            echo "ORG_LOCKED\n";
            fflush(STDOUT);
            $release('RESET');
        }
        if (in_array($mode, ['password', 'password-hold'], true)) {
            if (! app(InstitutionRecovery::class)->reset($data['organization'], $subject->public_id, $data['token'], 'Fictional-T17-Replacement-Only-2026!')) {
                throw new RuntimeException('Actual password broker reset failed.');
            }
            if ($mode === 'password-hold') {
                $db->commit();
            }
        } elseif ($mode === 'membership') {
            DB::transaction(function () use ($subject, $proof): void {
                DB::table('model_has_roles')->where('organization_id', $proof['organization_id'])->where('model_id', $subject->id)->delete();
                DB::table('organization_memberships')->where('organization_id', $proof['organization_id'])->where('user_id', $subject->id)->delete();
            });
        } elseif ($mode === 'permission') {
            app(RoleManagement::class)->syncPermissions($role, []);
        } elseif ($mode === 'grant') {
            app(RoleManagement::class)->syncPermissions($role, ['approaches.access', 'organization.units.manage']);
        } elseif (in_array($mode, ['role', 'deadlock'], true)) {
            app(RoleManagement::class)->revoke($role, $subject);
        } else {
            throw new RuntimeException('Unknown explicit investigation operation.');
        }
    });
} catch (QueryException $exception) {
    $error = (int) $exception->errorInfo[1];
    $status = 'rolled_back';
}
echo json_encode(['status' => $status, 'error' => $error, 'connection' => $identity->connection_id,
    'isolation' => $db->selectOne('SELECT @@transaction_isolation AS value')->value]), PHP_EOL;
