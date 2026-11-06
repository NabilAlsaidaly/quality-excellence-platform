<?php

// Test-only worker. Never bootstrap a configured application DB without the
// same explicit disposable-target checks as the MySQL integration base class.
use App\Actions\Identity\InstitutionRecovery;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

require dirname(__DIR__, 2).'/vendor/autoload.php';

if (getenv('MYSQL_TESTS_ENABLED') !== '1' || getenv('DB_DATABASE') !== 'quality_platform_ci'
    || getenv('APP_ENV') !== 'testing' || getenv('DB_URL') || file_exists(dirname(__DIR__, 2).'/bootstrap/cache/config.php')) {
    throw new RuntimeException('Identity worker requires explicit isolated MySQL test target.');
}
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
$actual = DB::selectOne('SELECT DATABASE() AS name, VERSION() AS version, @@transaction_isolation AS isolation');
if (! $app->environment('testing') || DB::getDriverName() !== 'mysql'
    || DB::connection()->getDatabaseName() !== 'quality_platform_ci'
    || $actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
    || $actual->isolation !== 'REPEATABLE-READ') {
    throw new RuntimeException('Actual identity-worker target is unsafe.');
}
$input = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
Notification::fake();
DB::beginTransaction();
try {
    // Establish a genuinely old REPEATABLE READ snapshot before waiting.
    DB::table('users')->where('public_id', $input['account'])->first();
    DB::table('institution_password_reset_tokens')->count();
    echo json_encode(['phase' => 'snapshot', 'connection_id' => (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id])."\n";
    echo json_encode(['phase' => 'requesting_lock'])."\n";
    fflush(STDOUT);
    $action = app(InstitutionRecovery::class);
    if ($input['mode'] === 'reset') {
        $accepted = $action->reset($input['organization'], $input['account'], $input['token'], 'worker-replacement-password');
    } else {
        $action->request($input['organization'], $input['email']);
        $accepted = null;
    }
    DB::commit();
    echo json_encode(['phase' => 'complete', 'accepted' => $accepted,
        'notifications' => count(Notification::sentNotifications())])."\n";
} catch (Throwable $error) {
    if (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    // Keep connection configuration, credential material and stack args private.
    echo json_encode(['phase' => 'error', 'class' => $error::class])."\n";
    exit(2);
}
