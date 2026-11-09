<?php

use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\User;
use App\Policies\ApproachPolicy;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\Browser\InstitutionIdentity\Environment;

require_once __DIR__.'/Environment.php';
try {
    if (PHP_SAPI !== 'cli') {
        throw new RuntimeException('CLI test operator only.');
    }
    $mode = $argv[1] ?? '';
    [$app, $kernel, $db, $config] = Environment::boot($mode === 'setup');
    $path = Environment::privateRoot().'/fixture.json';
    if ($mode === 'setup') {
        if (file_exists($path) || (int) $db->selectOne('SELECT COUNT(*) AS n FROM information_schema.tables WHERE table_schema = DATABASE()')->n !== 0) {
            throw new RuntimeException('Expected a new empty owned schema; no reset or adoption.');
        }
        if (Artisan::call('migrate', ['--force' => true]) !== 0) {
            throw new RuntimeException('Owned fixture migration failed.');
        }
        $accounts = [];
        foreach (['أ', 'ب'] as $name) {
            $organization = Organization::factory()->create(['name' => 'مؤسسة التحقق '.$name]);
            $password = bin2hex(random_bytes(18));
            $user = User::factory()->create(['name' => 'حساب الاختبار '.$name, 'principal_type' => 'institution',
                'organization_id' => $organization->id, 'email' => 'journey@example.test', 'password' => $password]);
            OrganizationMembership::factory()->create(['organization_id' => $organization->id, 'user_id' => $user->id]);
            $accounts[] = ['organization_id' => $organization->id, 'organization' => $organization->public_id,
                'id' => $user->id, 'account' => $user->public_id, 'name' => $user->name, 'email' => $user->email,
                'password' => $password, 'initial_hash' => $user->getAuthPassword()];
        }
        file_put_contents($path, json_encode(['session' => $config['session'], 'accounts' => $accounts,
            'new_password_a' => bin2hex(random_bytes(18))], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
        $db->statement("REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'identity_journey'@'%'");
        $db->statement("GRANT SELECT, INSERT, UPDATE, DELETE ON quality_platform_ci.* TO 'identity_journey'@'%'");
        echo "Prepared two fictional institutions/accounts with one contact email and no grants.\n";
        exit;
    }
    $fixture = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
    if ($fixture['session'] !== $config['session']) {
        throw new RuntimeException('Fixture ownership mismatch.');
    }
    foreach ($fixture['accounts'] as $record) {
        $user = User::findOrFail($record['id']);
        if ($user->public_id !== $record['account'] || (int) $user->organization_id !== $record['organization_id']
            || Organization::findOrFail($record['organization_id'])->public_id !== $record['organization']) {
            throw new RuntimeException('Fixture identity mismatch.');
        }
    }
    $a = $fixture['accounts'][0];
    if ($mode === 'expired-link') {
        $valid = file_get_contents(Environment::privateRoot().'/reset-0.txt');
        $token = rawurldecode(basename(parse_url($valid, PHP_URL_PATH)));
        $relative = URL::temporarySignedRoute('institution.reset', now()->subMinute(),
            ['organization' => $a['organization'], 'account' => $a['account'], 'token' => $token], absolute: false);
        file_put_contents(Environment::privateRoot().'/expired-reset.txt', config('app.url').$relative, LOCK_EX);
        echo "Prepared an expired signed link privately.\n";
    } elseif ($mode === 'expire-sessions') {
        foreach (glob(Environment::privateRoot().'/sessions/*') as $file) {
            if (is_file($file) && ! is_link($file)) {
                touch($file, time() - 180);
            }
        }
        echo "Expired only owned session-file timestamps.\n";
    } elseif ($mode === 'verify') {
        $first = User::findOrFail($a['id']);
        $second = User::findOrFail($fixture['accounts'][1]['id']);
        $result = ['database' => $db->getDatabaseName(), 'mysql84' => true,
            'same_email' => $first->email === $second->email,
            'a_password_reset' => Hash::check($fixture['new_password_a'], $first->getAuthPassword()),
            'a_old_password_denied' => ! Hash::check($a['password'], $first->getAuthPassword()),
            'b_unchanged' => hash_equals($fixture['accounts'][1]['initial_hash'], $second->getAuthPassword()),
            'b_password_valid' => Hash::check($fixture['accounts'][1]['password'], $second->getAuthPassword()),
            'no_roles' => $db->table('roles')->count() === 0 && $db->table('model_has_roles')->count() === 0,
            'no_permissions' => $db->table('permissions')->count() === 0 && config('authorization.capabilities') === [],
            'real_policy' => get_class(app(ApproachPolicy::class)) === ApproachPolicy::class,
            'reset_tokens_consumed' => $db->table('institution_password_reset_tokens')->count() === 0];
        foreach (array_diff_key($result, ['database' => true]) as $value) {
            if ($value !== true) {
                throw new RuntimeException('Independent identity readback failed.');
            }
        }
        file_put_contents(Environment::privateRoot().'/readback.json', json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
        echo json_encode($result), PHP_EOL;
    } else {
        throw new RuntimeException('Unknown explicit fixture operation.');
    }

} catch (Throwable $error) {
    fwrite(STDERR, 'Fixture operation failed at '.basename($error->getFile()).':'.$error->getLine().' ('.get_class($error).')'.PHP_EOL);
    exit(1);
}
