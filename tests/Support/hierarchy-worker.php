<?php

// Test-only subprocess, never an application command/route. JSON fixture payload
// arrives on stdin; database secrets stay in the child environment, not argv/logs.
use App\Actions\OrganizationalUnits\InvalidHierarchy;
use App\Actions\OrganizationalUnits\OrganizationalUnitManagement;
use App\Authorization\TenantContext;
use App\Models\OrganizationalUnit;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function emitHierarchyTest(array $event): void
{
    echo json_encode($event, JSON_THROW_ON_ERROR).PHP_EOL;
    fflush(STDOUT);
}

try {
    if (getenv('MYSQL_TESTS_ENABLED') !== '1' || getenv('APP_ENV') !== 'testing'
        || getenv('DB_CONNECTION') !== 'mysql' || getenv('DB_DATABASE') !== 'quality_platform_ci'
        || getenv('DB_URL') || file_exists(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
        throw new RuntimeException('Unsafe test environment');
    }
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $connection = DB::connection();
    $actual = DB::selectOne('SELECT DATABASE() AS name, VERSION() AS version, CONNECTION_ID() AS connection_id, @@transaction_isolation AS isolation');
    if (! $app->environment('testing') || $connection->getDriverName() !== 'mysql'
        || $connection->getDatabaseName() !== 'quality_platform_ci' || $actual->name !== 'quality_platform_ci'
        || ! str_starts_with($actual->version, '8.4.') || $actual->isolation !== 'REPEATABLE-READ') {
        throw new RuntimeException('Unsafe actual test target');
    }
    $payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $actor = User::query()->findOrFail($payload['actor_id']);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    DB::beginTransaction();
    // Deliberately establish a pre-commit MVCC snapshot in the second process.
    $snapshot = OrganizationalUnit::query()->where('organization_id', $payload['organization_id'])
        ->orderBy('id')->get(['public_id', 'parent_id'])->keyBy('public_id')->toArray();
    emitHierarchyTest(['phase' => 'snapshot', 'connection_id' => (int) $actual->connection_id,
        'isolation' => $actual->isolation, 'nodes' => $snapshot]);
    $armed = true;
    $connection->beforeExecuting(function (string $sql) use (&$armed): void {
        if ($armed && str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update')) {
            $armed = false;
            emitHierarchyTest(['phase' => 'requesting_lock']);
        }
    });
    try {
        app(TenantContext::class)->run($actor, $payload['organization_id'], fn () => app(OrganizationalUnitManagement::class)->move($actor, $payload['unit_public_id'], $payload['parent_public_id']));
        DB::commit();
        emitHierarchyTest(['phase' => 'committed']);
    } catch (InvalidHierarchy) {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        emitHierarchyTest(['phase' => 'rejected_cycle']);
    }
    exit(0);
} catch (Throwable $error) {
    if (isset($connection) && $connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    // No exception text/configuration or fixture names in subprocess failures.
    emitHierarchyTest(['phase' => 'error', 'class' => $error::class]);
    exit(2);
}
