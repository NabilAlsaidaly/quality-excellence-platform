<?php

use App\Actions\Approaches\CreateApproach;
use App\Actions\Approaches\ManageApproachAssignments;
use App\Authorization\ApproachAssignmentAccess;
use App\Authorization\TenantContext;
use App\Filament\Institution\Approaches\ApproachAccess;
use App\Models\Approach;
use App\Models\Organization;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Browser\ApproachOperations\Environment;
use Tests\Support\ApproachAssignmentFixtures;

require_once __DIR__.'/Environment.php';
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Explicit CLI fixture operator required.');
    }
    $mode = $argv[1] ?? '';
    [$app, $kernel, $db, $c] = Environment::boot($mode === 'setup');
    $file = Environment::privateRoot().'/fixture.json';
    $builder = new class
    {
        use ApproachAssignmentFixtures;

        public function build(): array
        {
            return $this->assignmentFixture();
        }

        public function enter(array $f, Closure $op): mixed
        {
            return $this->inAssignmentFixture($f, $op);
        }

        public function login(User $actor, $organization): void
        {
            $this->loginAssignmentActor($actor, $organization);
        }

        public function data(array $f, string $name): array
        {
            return $this->assignmentData($f, $name);
        }
    };
    if ($mode === 'setup') {
        if (file_exists($file) || (int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
            throw new RuntimeException('Expected empty owned schema; no reset/adoption.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Owned migration failed.');
        }
        $f = $builder->build();
        $foreign = $builder->build();
        $hidden = app(TenantContext::class)->run($f['quality'], $f['organization']->id,
            fn () => app(CreateApproach::class)->execute($f['quality'], $builder->data($f, 'غير مسندة ولا تظهر')));
        $foreignResource = app(TenantContext::class)->run($foreign['quality'], $foreign['organization']->id,
            fn () => app(CreateApproach::class)->execute($foreign['quality'], $builder->data($foreign, 'مورد مؤسسة أخرى')));
        $accounts = [];
        foreach (['quality', 'contributor', 'unassigned', 'issuer', 'foreign'] as $name) {
            $user = $name === 'foreign' ? $foreign['contributor'] : $f[$name];
            $organization = $name === 'foreign' ? $foreign['organization'] : $f['organization'];
            $password = bin2hex(random_bytes(18));
            $user->forceFill(['name' => 'حساب التحقق '.$name, 'password' => $password])->save();
            $accounts[$name] = ['email' => $user->email, 'password' => $password, 'organization' => $organization->public_id, 'id' => $user->id];
        }
        $livewire = collect(Route::getRoutes())->first(fn ($route) => in_array('POST', $route->methods(), true) && str_contains($route->getName() ?? '', 'livewire') && str_contains($route->uri(), 'update'));
        $payload = ['owner' => $c['fixture_id'], 'proof' => $f['proof'], 'accounts' => $accounts,
            'organization' => $f['organization']->public_id, 'unit' => $f['unit']->public_id,
            'hidden' => $hidden->public_id, 'foreign' => $foreignResource->public_id, 'resource' => null,
            'livewire_update' => $livewire === null ? null : '/'.ltrim($livewire->uri(), '/'),
            'livewire_script' => EndpointResolver::scriptPath(minified: true)];
        file_put_contents($file, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $db->statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'approach_fixture'@'%'");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON quality_platform_ci.* TO 'approach_fixture'@'%'");
        echo "Prepared named TEST_ONLY accounts, actual grants and isolated native UI fixture.\n";
        exit;
    }
    $f = Environment::fixture($c);
    $quality = User::findOrFail($f['proof']['quality_id']);
    $organization = Organization::findOrFail($f['proof']['organization_id']);
    if ($mode === 'bind-resource') {
        $id = $argv[2] ?? '';
        if (! Str::isUlid($id) || ! Approach::where('organization_id', $organization->id)->where('created_by', $quality->id)
            ->where('public_id', $id)->where('name', 'منهجية الرحلة المحدثة')->exists()) {
            throw new RuntimeException('Refuse unverified browser resource.');
        }
        $f['resource'] = $id;
        file_put_contents($file, json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        echo "Verified actual UI-created resource privately.\n";
        exit;
    }
    $resource = Approach::where('organization_id', $organization->id)->where('public_id', $f['resource'])->firstOrFail();
    ApproachAssignmentAccess::runFixture(request(), $f['proof'], function () use ($mode, $f, $quality, $resource, $organization, $builder): void {
        if ($mode === 'revoke-contributor') {
            app(TenantContext::class)->run($quality, $organization->id,
                fn () => app(ManageApproachAssignments::class)->revokeContributor($quality, $resource, User::findOrFail($f['proof']['contributor_id'])));
            echo "Contributor revoked through the actual scoped Action.\n";
        } elseif ($mode === 'expire-quality') {
            // Explicit clock-boundary simulation on this owned fixture only, preserving the 24h CHECK.
            $cutoff = now('UTC')->startOfSecond();
            DB::table('approach_assignments')->where('organization_id', $organization->id)->where('fixture_id', $f['proof']['fixture_id'])
                ->where('grantee_id', $quality->id)->update(['starts_at' => $cutoff->copy()->subHours(25)->format('Y-m-d H:i:s'), 'expires_at' => $cutoff->copy()->subHour()->format('Y-m-d H:i:s')]);
            $builder->login($quality, $organization);
            try {
                app(ApproachAccess::class)->create(['name' => 'must deny']);
                throw new RuntimeException('Expired authority accepted.');
            } catch (AuthorizationException) {
                echo "Owned expiry simulation rejected by actual Action; observation recorded.\n";
            }
        } elseif ($mode === 'verify-lifecycle') {
            $events = DB::table('approach_assignment_changes')->where('organization_id', $organization->id)->where('fixture_id', $f['proof']['fixture_id']);
            $result = [
                'four_contributor_revocations' => (clone $events)->where('event', 'revoked')->where('subject_id', $f['proof']['contributor_id'])->count() === 4,
                'expiry_observed_once' => (clone $events)->where('event', 'expiration_observed')->where('subject_id', $quality->id)->count() === 1,
                'expired_create_denied' => (clone $events)->where('event', 'access_denied')->where('subject_id', $quality->id)->where('operation', 'create')->exists(),
                'revisions_preserved' => DB::table('approach_content_revisions')->where('approach_id', $resource->id)->count() === 2,
                'metadata_history_preserved' => DB::table('approach_changes')->where('approach_id', $resource->id)->count() === 1,
            ];
            if (in_array(false, $result, true)) {
                throw new RuntimeException('Independent lifecycle readback failed.');
            }
            file_put_contents(Environment::privateRoot().'/lifecycle-readback.json', json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
            echo json_encode($result), PHP_EOL;
        } elseif ($mode === 'verify') {
            $changes = DB::table('approach_changes')->where('organization_id', $organization->id)->where('approach_id', $resource->id)->get();
            $revisions = DB::table('approach_content_revisions')->where('organization_id', $organization->id)->where('approach_id', $resource->id)->orderBy('version_number')->get();
            $result = ['actual_policy' => get_class(app(ApproachPolicy::class)) === ApproachPolicy::class,
                'name_persisted' => $resource->name === 'منهجية الرحلة المحدثة',
                'owner_persisted' => $resource->owning_unit_id === DB::table('organizational_units')->where('public_id', $f['unit'])->value('id'),
                'explicit_recipients' => DB::table('approach_assignments')->where('approach_id', $resource->id)->count() === 7,
                'metadata_audit' => $changes->count() === 1 && (int) $changes[0]->actor_id === $quality->id,
                'draft_versions' => $revisions->count() === 2 && $revisions[0]->body === 'المسودة الأولى من المتصفح' && $revisions[1]->body === 'المسودة الثانية من المتصفح',
                'draft_authorship' => $revisions->every(fn ($row) => (int) $row->actor_id === $f['proof']['contributor_id']),
                'grant_audit' => DB::table('approach_assignment_changes')->where('approach_id', $resource->id)->where('event', 'granted')->count() === 7];
            foreach ($result as $value) {
                if ($value !== true) {
                    throw new RuntimeException('Independent browser readback failed.');
                }
            }
            file_put_contents(Environment::privateRoot().'/readback.json', json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
            echo json_encode($result), PHP_EOL;
        } else {
            throw new RuntimeException('Unknown explicit fixture operation.');
        }
    });
} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture failed at '.basename($error->getFile()).':'.$error->getLine().' ('.get_class($error).')'.PHP_EOL);
    exit(1);
}
