<?php

// Deliberately inverted, test-only lock holder to inject a genuine1213. It is
// not a supported writer protocol and is never registered as an application API.
use App\Authorization\TenantAuthorization;
use App\Authorization\TenantContext;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $error): void {
    fwrite(STDERR, 'T17 deadlock worker failed: '.get_class($error).' at '.basename($error->getFile()).':'.$error->getLine().PHP_EOL);
    exit(1);
});
$data = json_decode(getenv('T17_DEADLOCK_DATA'), true, flags: JSON_THROW_ON_ERROR);
$proof = $data['proof'];
$db = DB::connection();
$identity = $db->selectOne('SELECT DATABASE() AS db,@@server_uuid AS uuid,VERSION() AS version,CONNECTION_ID() AS id');
if (PHP_SAPI !== 'cli' || ! $app->environment('testing') || getenv('MYSQL_TESTS_ENABLED') !== '1'
    || $identity->db !== 'quality_platform_ci' || ! str_starts_with($identity->version, '8.4.')
    || $identity->uuid !== $proof['server_uuid'] || config('database.connections.mysql.url')) {
    throw new RuntimeException('Unsafe deadlock injection target.');
}
$barrier = realpath($data['barrier']);
if ($barrier === false || ! str_starts_with($barrier, realpath(sys_get_temp_dir()).DIRECTORY_SEPARATOR)
    || ! str_starts_with(basename($barrier), 't17')) {
    throw new RuntimeException('Unowned deadlock barrier.');
}
$db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$db->statement('SET SESSION innodb_lock_wait_timeout = 5');
$issuer = User::findOrFail($proof['issuer_id']);
$db->beginTransaction();
for ($i = 0; $i < 32; $i++) {
    $db->table('cache')->insert(['key' => 't17-deadlock-'.$proof['fixture_id'].'-'.$i, 'value' => 'owned heavier test transaction', 'expiration' => 1]);
}
$db->table('users')->where('id', $proof['contributor_id'])->lockForUpdate()->first();
echo "USER_LOCKED\n";
fflush(STDOUT);
$deadline = microtime(true) + 8;
while (file_get_contents($barrier) !== 'CYCLE' && microtime(true) < $deadline) {
    usleep(10000);
}
if (file_get_contents($barrier) !== 'CYCLE') {
    throw new RuntimeException('Deadlock barrier not released.');
}
app(TenantContext::class)->run($issuer, $proof['organization_id'],
    fn () => app(TenantAuthorization::class)->withinMutation($issuer, fn () => null));
$db->commit();
echo json_encode(['status' => 'committed', 'connection' => $identity->id, 'isolation' => $db->selectOne('SELECT @@transaction_isolation AS n')->n], JSON_THROW_ON_ERROR), "\n";
