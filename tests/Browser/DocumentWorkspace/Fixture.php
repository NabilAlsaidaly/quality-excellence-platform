<?php

use App\Actions\Documents\DocumentWorkspace;
use App\Actions\Documents\ManageDocumentWorkspaceAssignments;
use App\Authorization\DocumentWorkspaceAccess;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Browser\DocumentWorkspace\Environment;
use Tests\Support\DocumentWorkspaceFixtures;

require_once __DIR__.'/Environment.php';
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Explicit CLI document fixture operator required.');
    }
    $mode = $argv[1] ?? '';
    [$app, $kernel, $db, $c] = Environment::boot($mode === 'setup');
    $path = Environment::privateRoot().'/fixture.json';
    $builder = new class
    {
        use DocumentWorkspaceFixtures;

        public function build(): array
        {
            return $this->documentFixture();
        }

        public function enter(array $f, Closure $callback): mixed
        {
            return $this->inDocumentFixture($f, $callback);
        }

        public function login(User $user, $organization): void
        {
            $this->loginDocumentActor($user, $organization);
        }

        public function hidden(array $f): string
        {
            return $this->unassignedWorkspaceDocument($f);
        }

        public function resource(array $f): string
        {
            return $this->createWorkspaceDocument($f, 'وثيقة المؤسسة الأخرى');
        }
    };
    if ($mode === 'setup') {
        if (file_exists($path) || (int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE()')->n !== 0) {
            throw new RuntimeException('Expected empty owned document schema; no reset/adoption.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Owned document migrations failed.');
        }
        $f = $builder->build();
        $foreign = $builder->build();
        $hidden = $builder->hidden($f);
        $foreignId = $builder->enter($foreign, fn () => $builder->resource($foreign));
        $accounts = [];
        foreach (['issuer', 'editor', 'reader', 'unassigned', 'foreign'] as $name) {
            $user = $name === 'foreign' ? $foreign['reader'] : $f[$name];
            $organization = $name === 'foreign' ? $foreign['organization'] : $f['organization'];
            $password = bin2hex(random_bytes(18));
            $user->forceFill(['name' => 'حساب اختبار '.$name, 'password' => $password])->save();
            $accounts[$name] = ['email' => $user->email, 'password' => $password, 'organization' => $organization->public_id, 'id' => $user->id];
        }
        foreach (['issuer', 'editor', 'unassigned'] as $name) {
            $foreign[$name]->forceFill(['password' => bin2hex(random_bytes(18))])->save();
        }
        $update = collect(Route::getRoutes())->first(fn ($route) => in_array('POST', $route->methods(), true) && str_contains($route->getName() ?? '', 'livewire') && str_contains($route->uri(), 'update'));
        $payload = ['owner' => $c['fixture_id'], 'proof' => $f['proof'], 'accounts' => $accounts, 'organization' => $f['organization']->public_id,
            'hidden' => $hidden, 'foreign' => $foreignId, 'resource' => null,
            'livewire_script' => EndpointResolver::scriptPath(minified: true), 'livewire_update' => '/'.$update->uri()];
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        $db->statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'document_fixture'@'%'");
        $db->statement("GRANT SELECT,INSERT,UPDATE,DELETE ON quality_platform_ci.* TO 'document_fixture'@'%'");
        echo "Prepared independent DOCUMENT_LOCAL_001 actors, actual Doc grants and native test panel; no J03 grants.\n";
        exit;
    }
    $f = Environment::fixture($c);
    $organization = Organization::where('public_id', $f['organization'])->firstOrFail();
    $editor = User::findOrFail($f['proof']['editor_id']);
    $issuer = User::findOrFail($f['proof']['issuer_id']);
    if ($mode === 'bind-resource') {
        $id = $argv[2] ?? '';
        if (! Str::isUlid($id) || ! DB::table('documents')->where('organization_id', $f['proof']['organization_id'])->where('public_id', $id)->where('created_by', $editor->id)->exists()) {
            throw new RuntimeException('Browser-created document could not be bound.');
        }
        $f['resource'] = $id;
        file_put_contents($path, json_encode($f, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        echo "Bound the actual browser-created document privately.\n";
        exit;
    }
    $document = DB::table('documents')->where('organization_id', $f['proof']['organization_id'])->where('public_id', $f['resource'])->first();
    if ($document === null) {
        throw new RuntimeException('Owned browser-created document required.');
    }
    if ($mode === 'revoke-reader') {
        $builder->login($issuer, $organization);
        DocumentWorkspaceAccess::runFixture(request(), $f['proof'], function () use ($issuer, $f, $document): void {
            $rows = DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('document_id', $document->id)->where('grantee_id', $f['proof']['reader_id'])->orderBy('id')->pluck('public_id');
            foreach ($rows as $id) {
                app(ManageDocumentWorkspaceAssignments::class)->revoke($issuer, $id);
            }
        });
        echo "Reader resource grants revoked through the actual named lifecycle Action.\n";
        exit;
    }
    if ($mode === 'expire-editor') {
        $end = now('UTC')->subSecond();
        DB::table('document_workspace_assignments')->where('fixture_id', $f['proof']['fixture_id'])->where('grantee_id', $editor->id)
            ->update(['starts_at' => $end->copy()->subDay()->format('Y-m-d H:i:s'), 'expires_at' => $end->format('Y-m-d H:i:s')]);
        $builder->login($editor, $organization);
        $denied = false;
        DocumentWorkspaceAccess::runFixture(request(), $f['proof'], function () use (&$denied): void {
            try {
                app(DocumentWorkspace::class)->create(['title' => 'expired fixture attempt', 'description' => 'must not persist']);
            } catch (AuthorizationException) {
                $denied = true;
            }
        });
        if (! $denied) {
            throw new RuntimeException('Expired document create unexpectedly succeeded.');
        }
        echo "Disclosed fixture-clock expiry denied by the actual Action; observation recorded.\n";
        exit;
    }
    if (! in_array($mode, ['verify', 'verify-lifecycle'], true)) {
        throw new RuntimeException('Unknown explicit document fixture operation.');
    }
    $versions = DB::table('document_versions')->where('organization_id', $document->organization_id)->where('document_id', $document->id)->orderBy('version_number')->get();
    $metadata = DB::table('document_metadata_revisions')->where('organization_id', $document->organization_id)->where('document_id', $document->id)->orderBy('version_number')->get();
    $checks = ['original_metadata_preserved' => $document->title === 'وثيقة الرحلة الفعلية',
        'metadata_persisted' => $metadata->count() === 1 && $metadata->first()->title === 'وثيقة الرحلة المحدثة' && (int) $metadata->first()->actor_id === $editor->id,
        'versions_and_authorship' => $versions->pluck('version_number')->map(intval(...))->all() === [1, 2] && $versions->every(fn ($row) => (int) $row->actor_id === $editor->id),
        'six_explicit_grants' => DB::table('document_workspace_assignments')->where('document_id', $document->id)->where('fixture_id', $f['proof']['fixture_id'])->count() === 6,
        'six_initial_audits' => DB::table('document_workspace_assignment_changes')->where('document_id', $document->id)->where('fixture_id', $f['proof']['fixture_id'])->where('event', 'granted')->count() === 6,
        'no_j03_permissions' => ! DB::table('permissions')->where('name', 'approaches.access')->exists(),
        'no_j03_assignments' => DB::table('approach_assignments')->count() === 0,
        'runtime_dml_only' => preg_match('/\b(?:ALL PRIVILEGES|CREATE|ALTER|DROP|TRIGGER|GRANT OPTION|SUPER)\b/', strtoupper(json_encode($db->select('SHOW GRANTS FOR CURRENT_USER()')))) !== 1];
    foreach ($versions as $version) {
        $checks['immutable_blob_'.$version->version_number] = hash_equals($version->sha256, hash('sha256', Storage::disk('local')->get($version->storage_key)));
    }
    if ($mode === 'verify-lifecycle') {
        $checks['two_reader_revocations'] = DB::table('document_workspace_assignment_changes')->where('fixture_id', $f['proof']['fixture_id'])->where('event', 'revoked')->where('subject_id', $f['proof']['reader_id'])->count() === 2;
        $checks['expiration_observed_once'] = DB::table('document_workspace_assignment_changes')->where('fixture_id', $f['proof']['fixture_id'])->where('event', 'expiration_observed')->count() === 1;
    }
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Independent document SQL readback failed: '.implode(',', array_keys(array_filter($checks, fn ($value) => ! $value))));
    }
    file_put_contents(Environment::privateRoot().'/'.$mode.'-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Document fixture failed at '.basename($error->getFile()).':'.$error->getLine().' ('.get_class($error).')'.PHP_EOL);
    exit(1);
}
