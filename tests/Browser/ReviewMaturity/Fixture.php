<?php

use App\Authorization\RoleManagement;
use App\Authorization\TenantContext;
use App\Models\Organization;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\Browser\ReviewMaturity\Environment;
use Tests\Support\ReviewMaturityFixtures;

require_once __DIR__.'/Environment.php';
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('Explicit owned fixture CLI required.');
    }
    $mode = $argv[1] ?? '';
    [$app, $kernel, $db, $c] = Environment::boot($mode === 'setup');
    $path = Environment::privateRoot().'/fixture.json';
    if ($mode === 'setup') {
        if (file_exists($path) || (int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema=DATABASE()')->n !== 0) {
            throw new RuntimeException('Expected empty owned workflow schema; no adoption/reset.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Owned migrations failed.');
        }
        $builder = new class
        {
            use ReviewMaturityFixtures;

            public function build(): array
            {
                return $this->reviewMaturityFixture(fakeStorage: false);
            }

            public function proof(array $f): array
            {
                return $this->workflowPanelProof($f);
            }
        };
        $f = $builder->build();
        $foreign = $builder->build();
        $unitProof = null;
        if (getenv('QEP_FINAL_UNITS_FIXTURE') === '1') {
            $f['organization']->forceFill(['name' => 'مؤسسة آفاق للجودة — بيانات عرض خيالية'])->save();
            $foreign['organization']->forceFill(['name' => 'مؤسسة أخرى — اختبار العزل فقط'])->save();
            // Explicit Issue50 TEST_ONLY role fixture; no normal registry,
            // seeder or existing demo is changed. Setup already refuses adoption.
            $oldCapabilities = config('authorization.capabilities');
            config(['authorization.capabilities' => array_merge(config('authorization.test_only_fixture_capabilities'), [
                'organization.units.view' => Organization::class, 'organization.units.manage' => Organization::class,
            ])]);
            try {
                app(TenantContext::class)->run($f['issuer'], $f['organization']->id, function () use ($f): void {
                    $manager = app(RoleManagement::class);
                    $role = $manager->create('unit-ui-'.$f['proof']['fixture_id']);
                    $manager->syncPermissions($role, ['organization.units.view', 'organization.units.manage']);
                    $manager->assign($role, $f['author']);
                    $manager->assign($role, $f['quality']);
                });
                $unitProof = ['scenario' => 'UNIT_LOCAL_001', 'fixture_id' => $f['proof']['fixture_id'],
                    'organization_id' => $f['organization']->id, 'actor_ids' => [$f['author']->id, $f['quality']->id]];
            } finally {
                config(['authorization.capabilities' => $oldCapabilities]);
            }
        }
        $accounts = [];
        foreach (['issuer', 'quality', 'author', 'reviewer', 'foreign'] as $name) {
            $user = $name === 'foreign' ? $foreign['reviewer'] : $f[$name];
            $organization = $name === 'foreign' ? $foreign['organization'] : $f['organization'];
            $password = bin2hex(random_bytes(18));
            $user->forceFill(['name' => 'حساب العرض '.$name, 'password' => $password])->save();
            $accounts[$name] = ['email' => $user->email, 'password' => $password, 'organization' => $organization->public_id];
        }
        foreach (['issuer', 'quality', 'author'] as $name) {
            $foreign[$name]->forceFill(['password' => bin2hex(random_bytes(18))])->save();
        }
        $update = collect(Route::getRoutes())->first(fn ($route) => in_array('POST', $route->methods(), true) && str_contains($route->getName() ?? '', 'livewire') && str_contains($route->uri(), 'update'));
        $payload = ['owner' => $c['fixture_id'], 'panel_proof' => $builder->proof($f), 'accounts' => $accounts, 'organization' => $f['organization']->public_id,
            'approach' => $f['approach']->public_id, 'foreign' => $foreign['approach']->public_id,
            'document' => $f['document_public_id'], 'stages' => DB::table('maturity_workflow_stages')->where('fixture_id', $f['proof']['fixture_id'])->orderBy('stage_ordinal')->pluck('public_id')->all(),
            'livewire_script' => EndpointResolver::scriptPath(minified: true), 'livewire_update' => '/'.$update->uri()];
        if ($unitProof !== null) {
            $payload['panel_proof']['organization_units'] = $unitProof;
        }
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), LOCK_EX);
        $db->statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'workflow_fixture'@'%'");
        $db->statement("GRANT SELECT,INSERT,UPDATE,DELETE ON quality_platform_ci.* TO 'workflow_fixture'@'%'");
        echo "Prepared four distinct local actors, unchanged real Policies, finite persisted grants and owned synthetic evidence.\n";
        exit;
    }
    if ($mode !== 'verify') {
        throw new RuntimeException('Unknown owned fixture operation.');
    }
    $f = Environment::fixture($c);
    $proof = $f['panel_proof']['workflow'];
    $query = fn (string $table) => DB::table($table)->where('organization_id', $proof['organization_id'])->where('fixture_id', $proof['fixture_id'])->where('approach_id', $proof['approach_id']);
    $assessments = $query('maturity_workflow_assessments')->orderBy('stage_ordinal')->get();
    $checks = ['real_approach_versions' => DB::table('approach_content_revisions')->where('organization_id', $proof['organization_id'])->where('approach_id', $proof['approach_id'])->count() === 2,
        'seven_immutable_review_rounds' => $query('review_maturity_reviews')->count() === 7,
        'independent_correction_preserved' => $query('review_maturity_reviews')->where('state', 'changes_requested')->count() === 1,
        'six_approved_reviews' => $query('review_maturity_reviews')->where('state', 'approved')->count() === 6,
        'five_initial_stages' => $assessments->pluck('stage_ordinal')->all() === [1, 2, 3, 4, 5],
        'manual_lowest_category' => $assessments->every(fn ($row) => $row->selected_band_index === 0),
        'five_actual_configurations' => $query('maturity_workflow_configurations')->count() === 5,
        'five_unrated_submissions' => $query('maturity_workflow_submissions')->get()->every(fn ($row) => ! array_key_exists('selected_band_index', json_decode($row->content_json, true))),
        'ten_expected_evidence_links' => $query('maturity_workflow_evidence')->count() === 10,
        'independent_actual_reviewer' => $query('review_maturity_reviews')->whereNotNull('reviewer_id')->get()->every(fn ($row) => $row->reviewer_id === $proof['reviewer_id']),
        'fourteen_atomic_notices' => $query('review_maturity_notifications')->count() === 14,
        'actual_read_state' => $query('review_maturity_notifications')->whereNotNull('read_at')->count() > 0,
        'runtime_dml_only' => preg_match('/\b(?:ALL PRIVILEGES|CREATE|ALTER|DROP|TRIGGER|GRANT OPTION|SUPER)\b/', strtoupper(json_encode($db->select('SHOW GRANTS FOR CURRENT_USER()')))) !== 1];
    $previous = null;
    foreach ($assessments as $assessment) {
        $checks['stage_chain_'.$assessment->stage_ordinal] = $assessment->previous_assessment_id === $previous;
        $previous = $assessment->id;
    }
    foreach ($proof['synthetic_versions'] as $versionId => $receipt) {
        $version = DB::table('document_versions')->where('organization_id', $proof['organization_id'])->where('public_id', $versionId)->first();
        $checks['owned_synthetic_bytes'] = $version !== null && hash_equals($receipt['sha256'], hash('sha256', Storage::disk('local')->get($version->storage_key)));
    }
    if (in_array(false, $checks, true)) {
        throw new RuntimeException('Readback failed: '.implode(',', array_keys(array_filter($checks, fn ($value) => ! $value))));
    }
    file_put_contents(Environment::privateRoot().'/verify-results.json', json_encode($checks, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    echo json_encode($checks, JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $error) {
    try {
        file_put_contents(Environment::privateRoot().'/fixture-diagnostic.json', json_encode(['type' => get_class($error), 'file' => basename($error->getFile()), 'line' => $error->getLine()], JSON_THROW_ON_ERROR));
    } catch (Throwable) {
    }
    fwrite(STDERR, 'Owned review/maturity fixture failed; private diagnostic retained.'.PHP_EOL);
    exit(1);
}
