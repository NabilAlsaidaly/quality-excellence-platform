<?php

// Guarded test-only process: fixture payload on stdin, credentials in environment.
use App\Actions\Organizations\OrganizationProvisioning;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function emitOrganizationTest(array $event): void
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
        throw new RuntimeException('Unsafe actual target');
    }
    $payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $actor = User::query()->findOrFail($payload['actor_id']);
    DB::statement('SET SESSION innodb_lock_wait_timeout = 15');
    DB::beginTransaction();
    $snapshot = Organization::query()->where('public_id', $payload['public_id'])->firstOrFail();
    emitOrganizationTest(['phase' => 'snapshot', 'connection_id' => (int) $actual->connection_id,
        'isolation' => $actual->isolation, 'previous_name' => $snapshot->name]);
    $armed = true;
    $connection->beforeExecuting(function (string $sql) use (&$armed): void {
        if ($armed && str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update')) {
            $armed = false;
            emitOrganizationTest(['phase' => 'requesting_lock']);
        }
    });
    // A mistaken no-op save must be visible beyond one-second DB precision.
    Date::setTestNow(now()->addMinute());
    $result = app(OrganizationProvisioning::class)->rename($actor, $payload['public_id'], $payload['new_name']);
    DB::commit();
    emitOrganizationTest(['phase' => 'committed', 'name' => $result->name, 'updated_at' => $result->getRawOriginal('updated_at')]);
    exit(0);
} catch (Throwable $error) {
    if (isset($connection) && $connection->transactionLevel() > 0) {
        $connection->rollBack();
    }
    emitOrganizationTest(['phase' => 'error', 'class' => $error::class]);
    exit(2);
}
