<?php

use App\Actions\Maturity\OpenStageReassessment;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\DocumentWorkspaceAccess;
use App\Authorization\ReviewMaturityAccess;
use App\Models\Organization;
use App\Models\ReviewMaturityReview;
use App\Models\User;
use App\Policies\ReviewMaturityPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

// Trusted parent fixture arrives over stdin; no credential or payload is logged.
require dirname(__DIR__, 2).'/vendor/autoload.php';
try {
    if (PHP_SAPI !== 'cli' || getenv('APP_ENV') !== 'testing' || getenv('MYSQL_TESTS_ENABLED') !== '1') {
        throw new RuntimeException('Owned CLI integration only.');
    }
    $input = json_decode(stream_get_contents(STDIN), true, 64, JSON_THROW_ON_ERROR);
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $db = DB::connection();
    $actual = $db->selectOne('SELECT DATABASE() AS name,@@server_uuid AS uuid,VERSION() AS version,CONNECTION_ID() AS connection');
    if (! $app->environment('testing') || $app->configurationIsCached() || $db->getDriverName() !== 'mysql' || $db->getConfig('url')
        || $actual->name !== 'quality_platform_ci' || ! str_starts_with($actual->version, '8.4.')
        || ! hash_equals($input['proof']['workflow']['server_uuid'], $actual->uuid) || ($input['mode'] ?? null) !== 'open_reassessment') {
        throw new RuntimeException('Wrong worker target or operation.');
    }
    $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $organization = Organization::findOrFail($input['proof']['workflow']['organization_id']);
    $actor = User::where('organization_id', $organization->id)->whereKey($input['actor'])->firstOrFail();
    Auth::guard('institution')->setUser($actor);
    app('session.store')->put(['identity.active_guard' => 'institution', 'institution.organization' => $organization->public_id,
        'institution.password_hash' => $input['session_hash']]);
    request()->setLaravelSession(app('session.store'));
    Gate::policy(ReviewMaturityReview::class, ReviewMaturityPolicy::class);
    echo 'CONNECTION:'.$actual->connection."\nISOLATION:".$db->selectOne('SELECT @@transaction_isolation AS level')->level."\nREADY\n";
    flush();
    $proof = $input['proof'];
    ApproachAssignmentAccess::runFixture(request(), $proof['approach'], fn () => DocumentWorkspaceAccess::runFixture(request(), $proof['document'],
        fn () => ReviewMaturityAccess::runFixture(request(), $proof['workflow'], fn () => app(OpenStageReassessment::class)->execute(
            $input['approach'], $input['stage'], $input['expected_assessment'], $input['request_key']))));
    echo "COMMITTED\n";
} catch (AuthorizationException) {
    echo "DENIED\n";
} catch (ConflictHttpException) {
    echo "CONFLICT\n";
} catch (Throwable $error) {
    fwrite(STDERR, 'WORKER_ERROR:'.get_class($error)."\n");
    exit(2);
}
